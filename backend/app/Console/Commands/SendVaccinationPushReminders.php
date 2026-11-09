<?php

namespace App\Console\Commands;

use App\Services\FcmClient;
use App\Services\FcmDeliveryUnknownException;
use App\Services\FcmPermanentException;
use App\Services\FcmRetryableException;
use App\Services\InvalidFcmTokenException;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SendVaccinationPushReminders extends Command
{
    private const MAX_ATTEMPTS = 3;

    protected $signature = 'babi:send-vaccination-push-reminders';

    protected $description = 'Send configured upcoming vaccination reminders to Owner devices';

    public function handle(FcmClient $fcm): int
    {
        $exhaustedClaims = DB::table('vaccination_push_deliveries')
            ->whereNull('sent_at')->whereNull('failed_at')->whereNull('cancelled_at')->whereNull('unknown_at')
            ->where('attempt_count', '>=', self::MAX_ATTEMPTS)
            ->where(fn ($query) => $query->whereNull('claimed_at')->orWhere('claimed_at', '<=', now()->subMinutes(5)))
            ->update([
                'failed_at' => now(), 'reason_code' => 'retry_limit_reached',
                'claim_token' => null, 'claimed_at' => null, 'retry_after' => null, 'updated_at' => now(),
            ]);
        if ($exhaustedClaims > 0) {
            $this->warn("Marked {$exhaustedClaims} exhausted vaccination push claim(s) as failed.");
        }

        if (! $fcm->isConfigured()) {
            if (Cache::add('babi:fcm:vaccination-configuration-warning', true, now()->addHour())) {
                Log::warning('Vaccination reminder push is paused because Firebase credentials are not configured.');
            }
            $this->warn('Firebase is not configured; vaccination push reminders remain queued.');

            return self::SUCCESS;
        }

        $sent = 0;
        $failures = 0;
        DB::table('vaccination_push_deliveries')->whereNull('sent_at')->whereNull('failed_at')->whereNull('cancelled_at')
            ->whereNull('unknown_at')->where(fn ($query) => $query->whereNull('retry_after')->orWhere('retry_after', '<=', now()))
            ->orderBy('id')->chunkById(100, function ($deliveries) use ($fcm, &$sent, &$failures) {
                foreach ($deliveries as $delivery) {
                    $this->deliver($delivery, $fcm, $sent, $failures);
                }
            });

        $this->info("Accepted {$sent} vaccination push reminder(s).".($failures ? " {$failures} attempt(s) failed and will retry." : ''));

        return $failures ? self::FAILURE : self::SUCCESS;
    }

    private function deliver(object $candidate, FcmClient $fcm, int &$sent, int &$failures): void
    {
        $claim = (string) Str::uuid();
        $claimed = DB::table('vaccination_push_deliveries')->where('id', $candidate->id)
            ->where('attempt_count', '<', self::MAX_ATTEMPTS)
            ->whereNull('sent_at')->whereNull('failed_at')->whereNull('cancelled_at')->whereNull('unknown_at')
            ->where(fn ($query) => $query->whereNull('retry_after')->orWhere('retry_after', '<=', now()))
            ->where(fn ($query) => $query->whereNull('claimed_at')->orWhere('claimed_at', '<=', now()->subMinutes(5)))
            ->update(['claim_token' => $claim, 'claimed_at' => now(), 'attempt_count' => DB::raw('attempt_count + 1'), 'updated_at' => now()]);
        if ($claimed !== 1) {
            return;
        }

        try {
            $delivery = DB::table('vaccination_push_deliveries')->where('id', $candidate->id)->first();
            $schedule = $delivery ? DB::table('vaccination_schedules')
                ->join('child_profiles', 'child_profiles.id', '=', 'vaccination_schedules.child_profile_id')
                ->join('vaccine_templates', 'vaccine_templates.id', '=', 'vaccination_schedules.vaccine_template_id')
                ->where('vaccination_schedules.id', $delivery->vaccination_schedule_id)
                ->whereIn('vaccination_schedules.status', ['unrecorded', 'pending'])
                ->whereExists(fn ($query) => $query->selectRaw('1')->from('child_data_consents')
                    ->whereColumn('child_data_consents.child_profile_id', 'child_profiles.id')
                    ->where('child_data_consents.purpose', 'storage')->whereNull('child_data_consents.withdrawn_at'))
                ->select('vaccination_schedules.appointment_date', 'vaccination_schedules.due_date', 'child_profiles.id as child_profile_id', 'child_profiles.family_space_id', 'child_profiles.name as child_name', 'vaccine_templates.name as vaccine_name')
                ->first() : null;
            $reminderDate = $schedule?->appointment_date ?? $schedule?->due_date;
            $userId = $delivery?->user_id;
            $device = $delivery && $userId
                ? DB::table('device_tokens')->where('id', $delivery->device_token_id)->where('user_id', $userId)->first()
                : null;
            $owner = $schedule && $userId
                ? DB::table('family_members')->join('users', 'users.id', '=', 'family_members.user_id')
                    ->where('family_members.family_space_id', $schedule->family_space_id)
                    ->where('family_members.user_id', $userId)->where('family_members.role', 'owner')
                    ->where('users.status', 'active')->exists()
                : false;
            $preference = $userId ? DB::table('notification_preferences')->where('user_id', $userId)->first() : null;
            $enabled = ! $preference || (bool) $preference->vaccination_enabled;
            $settings = $schedule ? DB::table('vaccination_reminder_settings')->where('family_space_id', $schedule->family_space_id)->first() : null;
            $daysBefore = $settings ? json_decode($settings->days_before, true) : [7];
            $startDate = $settings?->start_date ?? CarbonImmutable::today(config('babi.calendar_timezone'))->toDateString();
            $sendTime = substr($settings?->send_time ?? '08:00', 0, 5);
            $today = CarbonImmutable::today(config('babi.calendar_timezone'));
            $scheduledSendDate = $delivery
                ? CarbonImmutable::parse($delivery->reminder_date, config('babi.calendar_timezone'))->subDays((int) $delivery->days_before)->toDateString()
                : null;
            $settingAllows = $delivery && is_array($daysBefore)
                && in_array((int) $delivery->days_before, array_map('intval', $daysBefore), true)
                && $today->toDateString() >= $startDate
                && $scheduledSendDate === $today->toDateString();

            if (! $schedule || ! $reminderDate || $reminderDate !== $delivery->reminder_date || ! $owner || ! $enabled || ! $device || ! $settingAllows) {
                $this->cancel($candidate->id, $claim);

                return;
            }

            if (now(config('babi.calendar_timezone'))->format('H:i') < $sendTime) {
                $this->release($candidate->id, $claim);

                return;
            }

            $dateBasis = $schedule->appointment_date ? 'ngày hẹn từ cơ sở tiêm' : 'ngày tham khảo';
            $body = "Còn {$delivery->days_before} ngày đến lịch {$schedule->vaccine_name} ({$dateBasis}: {$reminderDate}). Đây là lịch dự kiến, hãy kiểm tra lại với cơ sở tiêm.";
            $fcm->send(Crypt::decryptString($device->fcm_token), "Lịch tiêm sắp tới cho bé {$schedule->child_name}", $body, [
                'type' => 'vaccination_reminder',
                'vaccination_schedule_id' => $delivery->vaccination_schedule_id,
                'child_profile_id' => $schedule->child_profile_id,
            ]);
            DB::table('vaccination_push_deliveries')->where('id', $candidate->id)->where('claim_token', $claim)
                ->update(['sent_at' => now(), 'claim_token' => null, 'claimed_at' => null, 'retry_after' => null, 'updated_at' => now()]);
            $sent++;
        } catch (InvalidFcmTokenException) {
            DB::table('device_tokens')->where('id', $candidate->device_token_id)->delete();
            DB::table('vaccination_push_deliveries')->where('id', $candidate->id)->where('claim_token', $claim)
                ->update(['failed_at' => now(), 'reason_code' => 'invalid_device_token', 'device_token_id' => null, 'claim_token' => null, 'claimed_at' => null, 'updated_at' => now()]);
        } catch (FcmDeliveryUnknownException) {
            DB::table('vaccination_push_deliveries')->where('id', $candidate->id)->where('claim_token', $claim)
                ->update(['unknown_at' => now(), 'reason_code' => 'provider_result_unknown', 'claim_token' => null, 'claimed_at' => null, 'updated_at' => now()]);
            $failures++;
        } catch (FcmPermanentException) {
            DB::table('vaccination_push_deliveries')->where('id', $candidate->id)->where('claim_token', $claim)
                ->update(['failed_at' => now(), 'reason_code' => 'provider_rejected', 'claim_token' => null, 'claimed_at' => null, 'updated_at' => now()]);
            $failures++;
        } catch (FcmRetryableException) {
            $this->retryOrFail($candidate, $claim, 'provider_temporary_error');
            $failures++;
        } catch (\Throwable) {
            $this->retryOrFail($candidate, $claim, 'delivery_error');
            report(new \RuntimeException('Vaccination push reminder delivery failed.'));
            $failures++;
        }
    }

    private function cancel(int $id, string $claim): void
    {
        DB::table('vaccination_push_deliveries')->where('id', $id)->where('claim_token', $claim)
            ->update(['cancelled_at' => now(), 'claim_token' => null, 'claimed_at' => null, 'updated_at' => now()]);
    }

    private function release(int $id, string $claim): void
    {
        DB::table('vaccination_push_deliveries')->where('id', $id)->where('claim_token', $claim)
            ->update(['claim_token' => null, 'claimed_at' => null, 'updated_at' => now()]);
    }

    private function retryOrFail(object $candidate, string $claim, string $reason): void
    {
        $attempt = (int) $candidate->attempt_count + 1;
        $terminal = $attempt >= self::MAX_ATTEMPTS;
        DB::table('vaccination_push_deliveries')->where('id', $candidate->id)->where('claim_token', $claim)->update([
            'failed_at' => $terminal ? now() : null,
            'reason_code' => $terminal ? 'retry_limit_reached' : $reason,
            'claim_token' => null,
            'claimed_at' => null,
            'retry_after' => $terminal ? null : now()->addMinutes(15 * (2 ** ($attempt - 1))),
            'updated_at' => now(),
        ]);
    }
}
