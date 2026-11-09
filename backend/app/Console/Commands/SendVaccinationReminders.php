<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class SendVaccinationReminders extends Command
{
    private const MAX_ATTEMPTS = 3;

    protected $signature = 'babi:send-vaccination-reminders {--date= : Test date in YYYY-MM-DD format} {--time= : Test time in HH:MM format}';

    protected $description = 'Email vaccination reminders on the configured days before each appointment';

    public function handle(): int
    {
        DB::table('vaccination_reminder_deliveries')
            ->whereNull('sent_at')->whereNull('failed_at')
            ->where('attempt_count', '>=', self::MAX_ATTEMPTS)
            ->where(fn ($query) => $query->whereNull('claimed_at')->orWhere('claimed_at', '<=', now()->subMinutes(15)))
            ->update([
                'failed_at' => now(),
                'claim_token' => null,
                'claimed_at' => null,
                'retry_after' => null,
                'reason_code' => 'retry_limit_reached',
                'updated_at' => now(),
            ]);

        $today = $this->option('date')
            ? Carbon::parse($this->option('date'))->startOfDay()
            : today('Asia/Ho_Chi_Minh');
        $sent = 0;
        $failures = 0;

        $spaces = DB::table('family_spaces')->pluck('id');
        foreach ($spaces as $spaceId) {
            $settings = DB::table('vaccination_reminder_settings')->where('family_space_id', $spaceId)->first();
            $sendTime = substr($settings->send_time ?? '08:00', 0, 5);
            $startDate = $settings->start_date ?? $today->toDateString();
            $runTime = $this->option('time') ?: now('Asia/Ho_Chi_Minh')->format('H:i');
            // The scheduler runs once per minute and may start late under load.
            // Send on the first run at or after the configured local time; the
            // delivery claim/idempotency key prevents later runs from duplicating it.
            if ($today->toDateString() < $startDate || $runTime < $sendTime) {
                continue;
            }
            $daysList = $settings ? json_decode($settings->days_before, true) : [7];
            $extraEmails = $settings ? json_decode($settings->recipient_emails, true) : [];
            foreach ($daysList as $days) {
                $appointmentDate = $today->copy()->addDays((int) $days)->toDateString();
                $appointments = DB::table('vaccination_schedules')
                    ->join('child_profiles', 'child_profiles.id', '=', 'vaccination_schedules.child_profile_id')
                    ->join('vaccine_templates', 'vaccine_templates.id', '=', 'vaccination_schedules.vaccine_template_id')
                    ->whereExists(fn ($query) => $query->selectRaw('1')->from('child_data_consents')
                        ->whereColumn('child_data_consents.child_profile_id', 'child_profiles.id')
                        ->where('child_data_consents.purpose', 'storage')->whereNull('child_data_consents.withdrawn_at'))
                    ->where('child_profiles.family_space_id', $spaceId)
                    ->where(function ($query) use ($appointmentDate) {
                        $query->whereDate('vaccination_schedules.appointment_date', $appointmentDate)
                            ->orWhere(function ($query) use ($appointmentDate) {
                                $query->whereNull('vaccination_schedules.appointment_date')
                                    ->whereDate('vaccination_schedules.due_date', $appointmentDate);
                            });
                    })
                    ->whereIn('vaccination_schedules.status', ['unrecorded', 'pending'])
                    ->select('vaccination_schedules.id', 'vaccination_schedules.child_profile_id', 'child_profiles.family_space_id', 'child_profiles.name as child_name', 'vaccine_templates.name as vaccine_name', 'vaccination_schedules.appointment_date', 'vaccination_schedules.due_date')
                    ->orderBy('vaccination_schedules.id')->get();

                foreach ($appointments as $appointment) {
                    $owners = DB::table('family_members')->join('family_spaces', 'family_spaces.id', '=', 'family_members.family_space_id')
                        ->join('users', 'users.id', '=', 'family_members.user_id')
                        ->where('family_spaces.id', DB::table('child_profiles')->where('id', $appointment->child_profile_id)->value('family_space_id'))
                        ->where('family_members.role', 'owner')->where('users.status', 'active')
                        ->select('users.id', 'users.email', 'users.name', 'users.email_verified_at')->get();

                    $recipients = $owners->whereNotNull('email_verified_at')->pluck('email')
                        ->merge($extraEmails)->map(fn ($email) => mb_strtolower(trim($email)))->unique()->values();
                    $date = $appointment->appointment_date ?? $appointment->due_date;
                    $this->queuePushDeliveries($appointment->id, $date, (int) $days, $owners);
                    if ($recipients->isEmpty()) {
                        $this->warn("No recipient for appointment {$appointment->id}.");

                        continue;
                    }

                    $dateBasis = $appointment->appointment_date ? 'ngày hẹn từ cơ sở tiêm' : 'ngày tham khảo';
                    $body = "Chào %s,\n\nBé %s có lịch tiêm dự kiến sau %d ngày (%s, %s): %s.\nVui lòng kiểm tra lại lịch và cơ sở tiêm trong Babi. Đây là lịch dự kiến, không phải xác nhận bé chưa tiêm.\n\nBabi";
                    foreach ($recipients as $email) {
                        $identity = [
                            'vaccination_schedule_id' => $appointment->id, 'reminder_date' => $date,
                            'days_before' => $days, 'email' => $email,
                        ];
                        DB::table('vaccination_reminder_deliveries')->insertOrIgnore($identity + [
                            'created_at' => now(), 'updated_at' => now(),
                        ]);

                        // Claim atomically so overlapping scheduler instances cannot send the same reminder.
                        $claimToken = (string) Str::uuid();
                        $claimed = DB::table('vaccination_reminder_deliveries')
                            ->where($identity)
                            ->whereNull('sent_at')
                            ->whereNull('failed_at')
                            ->where('attempt_count', '<', self::MAX_ATTEMPTS)
                            ->where(fn ($query) => $query->whereNull('retry_after')->orWhere('retry_after', '<=', now()))
                            ->where(fn ($query) => $query->whereNull('claimed_at')->orWhere('claimed_at', '<=', now()->subMinutes(15)))
                            ->update(['claim_token' => $claimToken, 'claimed_at' => now(), 'updated_at' => now()]);
                        if ($claimed !== 1) {
                            continue;
                        }

                        try {
                            $currentSchedule = DB::table('vaccination_schedules')
                                ->join('child_profiles', 'child_profiles.id', '=', 'vaccination_schedules.child_profile_id')
                                ->join('vaccine_templates', 'vaccine_templates.id', '=', 'vaccination_schedules.vaccine_template_id')
                                ->where('vaccination_schedules.id', $appointment->id)
                                ->where('vaccination_schedules.child_profile_id', $appointment->child_profile_id)
                                ->where('child_profiles.family_space_id', $spaceId)
                                ->whereIn('vaccination_schedules.status', ['unrecorded', 'pending'])
                                ->whereExists(fn ($query) => $query->selectRaw('1')->from('child_data_consents')
                                    ->whereColumn('child_data_consents.child_profile_id', 'child_profiles.id')
                                    ->where('child_data_consents.purpose', 'storage')->whereNull('child_data_consents.withdrawn_at'))
                                ->select('vaccination_schedules.appointment_date', 'vaccination_schedules.due_date', 'child_profiles.name as child_name', 'vaccine_templates.name as vaccine_name')
                                ->first();
                            $currentDate = $currentSchedule?->appointment_date ?? $currentSchedule?->due_date;
                            $isOwnerEmail = DB::table('family_members')->join('users', 'users.id', '=', 'family_members.user_id')
                                ->where('family_members.family_space_id', $spaceId)->where('family_members.role', 'owner')
                                ->where('users.status', 'active')->whereNotNull('users.email_verified_at')
                                ->whereRaw('LOWER(users.email) = ?', [$email])->exists();
                            $latestSettings = DB::table('vaccination_reminder_settings')->where('family_space_id', $spaceId)->first();
                            $latestExtraEmails = json_decode($latestSettings->recipient_emails ?? '[]', true);
                            $latestDays = json_decode($latestSettings->days_before ?? '[7]', true);
                            $latestSendTime = substr($latestSettings->send_time ?? '08:00', 0, 5);
                            $latestStartDate = $latestSettings->start_date ?? $today->toDateString();
                            $isCurrentExtraEmail = is_array($latestExtraEmails)
                                && in_array($email, array_map(fn ($value) => mb_strtolower(trim((string) $value)), $latestExtraEmails), true);
                            $settingsStillAllowDelivery = is_array($latestDays)
                                && in_array((int) $days, array_map('intval', $latestDays), true)
                                && $today->toDateString() >= $latestStartDate
                                && $runTime >= $latestSendTime;
                            if (! $currentSchedule || $currentDate !== $date || ! $settingsStillAllowDelivery || (! $isOwnerEmail && ! $isCurrentExtraEmail)) {
                                DB::table('vaccination_reminder_deliveries')->where($identity)->where('claim_token', $claimToken)
                                    ->update(['claim_token' => null, 'claimed_at' => null, 'updated_at' => now()]);

                                continue;
                            }
                            DB::table('vaccination_reminder_deliveries')->where($identity)->where('claim_token', $claimToken)->increment('attempt_count', 1, ['updated_at' => now()]);
                            Mail::raw(sprintf($body, 'bạn', $currentSchedule->child_name, $days, $date, $dateBasis, $currentSchedule->vaccine_name), fn ($mail) => $mail->to($email)->subject("Nhắc lịch tiêm cho bé {$currentSchedule->child_name}"));
                            DB::table('vaccination_reminder_deliveries')->where($identity)->where('claim_token', $claimToken)->update(['sent_at' => now(), 'claim_token' => null, 'claimed_at' => null, 'retry_after' => null, 'reason_code' => null, 'updated_at' => now()]);
                            $sent++;
                        } catch (\Throwable $e) {
                            $delivery = DB::table('vaccination_reminder_deliveries')->where($identity)->where('claim_token', $claimToken)->first();
                            $attemptCount = (int) ($delivery->attempt_count ?? self::MAX_ATTEMPTS);
                            $terminal = $attemptCount >= self::MAX_ATTEMPTS;
                            DB::table('vaccination_reminder_deliveries')->where($identity)->where('claim_token', $claimToken)->update([
                                'claim_token' => null,
                                'claimed_at' => null,
                                'retry_after' => $terminal ? null : now()->addMinutes($attemptCount === 1 ? 15 : 30),
                                'failed_at' => $terminal ? now() : null,
                                'reason_code' => $terminal ? 'retry_limit_reached' : 'mail_transport_failed',
                                'updated_at' => now(),
                            ]);
                            report($e);
                            $this->error("Could not send reminder for appointment {$appointment->id}.");
                            $failures++;
                        }
                    }
                }
            }
        }

        $this->info("Accepted {$sent} vaccination reminder email(s).".($failures ? " {$failures} send attempt(s) failed; retry policy is 15/30 minutes, up to ".self::MAX_ATTEMPTS.' attempts.' : ''));

        return $failures ? self::FAILURE : self::SUCCESS;
    }

    private function queuePushDeliveries(int $scheduleId, string $date, int $daysBefore, object $owners): void
    {
        foreach ($owners as $owner) {
            $preference = DB::table('notification_preferences')->where('user_id', $owner->id)->first();
            if ($preference && ! $preference->vaccination_enabled) {
                continue;
            }
            foreach (DB::table('device_tokens')->where('user_id', $owner->id)->orderBy('id')->pluck('id') as $deviceId) {
                DB::table('vaccination_push_deliveries')->insertOrIgnore([
                    'vaccination_schedule_id' => $scheduleId,
                    'reminder_date' => $date,
                    'days_before' => $daysBefore,
                    'user_id' => $owner->id,
                    'device_token_id' => $deviceId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}
