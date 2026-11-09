<?php

namespace App\Console\Commands;

use App\Services\FcmClient;
use App\Services\FcmDeliveryUnknownException;
use App\Services\FcmPermanentException;
use App\Services\InvalidFcmTokenException;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class SendMilestoneReminders extends Command
{
    private const MAX_ATTEMPTS = 3;

    protected $signature = 'babi:send-milestone-reminders {--date= : Test date in YYYY-MM-DD format} {--time= : Test time in HH:MM format}';

    protected $description = 'Send birthday-derived milestone reminders to eligible Owner devices';

    public function handle(FcmClient $fcm): int
    {
        $exhaustedClaims = DB::table('milestone_notification_deliveries')
            ->where('status', 'processing')->where('attempt_count', '>=', self::MAX_ATTEMPTS)
            ->where(fn ($query) => $query->whereNull('claimed_at')->orWhere('claimed_at', '<=', now()->subMinutes(5)))
            ->update([
                'status' => 'failed',
                'reason_code' => 'retry_limit_reached',
                'claim_token' => null,
                'claimed_at' => null,
                'retry_after' => null,
                'updated_at' => now(),
            ]);
        if ($exhaustedClaims > 0) {
            $this->warn("Marked {$exhaustedClaims} exhausted milestone delivery claim(s) as failed.");
        }

        $timezone = config('babi.calendar_timezone', 'Asia/Ho_Chi_Minh');
        $today = $this->option('date')
            ? CarbonImmutable::parse($this->option('date'), $timezone)->startOfDay()
            : CarbonImmutable::today($timezone);
        $time = $this->option('time') ?: now($timezone)->format('H:i');
        if ($time < '08:00') {
            $this->info('Before 08:00 local time; no milestone notifications are due.');

            return self::SUCCESS;
        }
        if (! $fcm->isConfigured()) {
            if (Cache::add('babi:fcm:milestone-configuration-warning', true, now()->addHour())) {
                Log::warning('Milestone reminder push is paused because Firebase credentials are not configured.');
            }
            $this->warn('Firebase is not configured; milestone reminders remain queued.');

            return self::SUCCESS;
        }

        $sent = 0;
        $failed = 0;
        foreach ([7, 1] as $daysBefore) {
            $date = $today->addDays($daysBefore)->toDateString();
            $reminders = DB::table('milestone_reminders')->whereDate('reminder_date', $date)
                ->whereIn('status', ['pending', 'send_accepted'])->whereNull('cancelled_at')->orderBy('id')->get();
            foreach ($reminders as $reminder) {
                $this->queueEligibleOwners((int) $reminder->id, $daysBefore);
            }
            DB::table('milestone_notification_deliveries')->where('days_before', $daysBefore)
                ->whereIn('status', ['pending', 'processing'])->where('attempt_count', '<', self::MAX_ATTEMPTS)
                ->whereExists(fn ($query) => $query->selectRaw('1')->from('milestone_reminders')
                    ->whereColumn('milestone_reminders.id', 'milestone_notification_deliveries.milestone_reminder_id')
                    ->whereDate('milestone_reminders.reminder_date', $date)->whereNull('milestone_reminders.cancelled_at')
                    ->whereIn('milestone_reminders.status', ['pending', 'send_accepted']))
                ->orderBy('id')->chunkById(100, function ($deliveries) use ($fcm, $today, &$sent, &$failed): void {
                    foreach ($deliveries as $delivery) {
                        $this->deliver($delivery, $fcm, $today->toDateString(), $sent, $failed);
                    }
                });
        }

        $this->info("Accepted {$sent} milestone notification(s).".($failed ? " {$failed} delivery attempt(s) failed." : ''));

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function queueEligibleOwners(int $reminderId, int $daysBefore): void
    {
        $reminder = DB::table('milestone_reminders')->where('id', $reminderId)->whereNull('cancelled_at')
            ->whereIn('status', ['pending', 'send_accepted'])->first();
        if (! $reminder || ! DB::table('child_data_consents')->where('child_profile_id', $reminder->child_profile_id)
            ->where('purpose', 'storage')->whereNull('withdrawn_at')->exists()) {
            return;
        }
        $owners = DB::table('family_members')->join('child_profiles', 'child_profiles.family_space_id', '=', 'family_members.family_space_id')
            ->join('users', 'users.id', '=', 'family_members.user_id')
            ->where('child_profiles.id', $reminder->child_profile_id)->where('family_members.role', 'owner')
            ->where('users.status', 'active')->select('users.id')->get();
        foreach ($owners as $owner) {
            $preferences = DB::table('notification_preferences')->where('user_id', $owner->id)->first();
            if ($preferences && ! $preferences->milestone_enabled) {
                continue;
            }
            foreach (DB::table('device_tokens')->where('user_id', $owner->id)->orderBy('id')->get(['id', 'token_hash']) as $device) {
                DB::table('milestone_notification_deliveries')->insertOrIgnore([
                    'milestone_reminder_id' => $reminderId,
                    'days_before' => $daysBefore,
                    'user_id' => $owner->id,
                    'device_token_id' => $device->id,
                    'device_key' => $device->token_hash,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    private function deliver(object $candidate, FcmClient $fcm, string $runDate, int &$sent, int &$failed): void
    {
        $claim = (string) Str::uuid();
        $claimed = DB::table('milestone_notification_deliveries')->where('id', $candidate->id)
            ->whereIn('status', ['pending', 'processing'])->where('attempt_count', '<', self::MAX_ATTEMPTS)
            ->where(fn ($query) => $query->whereNull('retry_after')->orWhere('retry_after', '<=', now()))
            ->where(fn ($query) => $query->whereNull('claimed_at')->orWhere('claimed_at', '<=', now()->subMinutes(5)))
            ->update(['status' => 'processing', 'claim_token' => $claim, 'claimed_at' => now(), 'attempt_count' => DB::raw('attempt_count + 1'), 'updated_at' => now()]);
        if ($claimed !== 1) {
            return;
        }
        $delivery = DB::table('milestone_notification_deliveries')->where('id', $candidate->id)->first();
        try {
            $context = DB::table('milestone_reminders')->join('milestone_templates', 'milestone_templates.id', '=', 'milestone_reminders.milestone_template_id')
                ->join('child_profiles', 'child_profiles.id', '=', 'milestone_reminders.child_profile_id')
                ->where('milestone_reminders.id', $delivery->milestone_reminder_id)->whereNull('milestone_reminders.cancelled_at')
                ->whereIn('milestone_reminders.status', ['pending', 'send_accepted'])
                ->whereExists(fn ($query) => $query->selectRaw('1')->from('child_data_consents')->whereColumn('child_data_consents.child_profile_id', 'child_profiles.id')
                    ->where('child_data_consents.purpose', 'storage')->whereNull('child_data_consents.withdrawn_at'))
                ->select('milestone_reminders.reminder_date', 'milestone_reminders.id as reminder_id', 'milestone_templates.name as milestone_name',
                    'child_profiles.id as child_id', 'child_profiles.name as child_name', 'child_profiles.family_space_id')->first();
            $owner = $context ? DB::table('family_members')->join('users', 'users.id', '=', 'family_members.user_id')
                ->where('family_members.family_space_id', $context->family_space_id)->where('family_members.user_id', $delivery->user_id)
                ->where('family_members.role', 'owner')->where('users.status', 'active')->exists() : false;
            $device = DB::table('device_tokens')->where('id', $delivery->device_token_id)->where('user_id', $delivery->user_id)->first();
            $preferences = DB::table('notification_preferences')->where('user_id', $delivery->user_id)->first();
            $enabled = ! $preferences || (bool) $preferences->milestone_enabled;
            $offsetDue = $context && CarbonImmutable::parse($context->reminder_date)->subDays((int) $delivery->days_before)->toDateString() === $runDate;
            if (! $context || ! $owner || ! $device || ! $enabled || ! $offsetDue) {
                $this->finish($candidate->id, $claim, 'skipped', 'eligibility_changed');

                return;
            }

            $providerMessageId = $fcm->send(Crypt::decryptString($device->fcm_token), "Sắp đến cột mốc của bé {$context->child_name}",
                "Còn {$delivery->days_before} ngày đến mốc {$context->milestone_name}. Cùng gia đình lưu lại khoảnh khắc của bé nhé.", [
                    'type' => 'milestone_reminder', 'milestone_reminder_id' => $delivery->milestone_reminder_id,
                    'child_profile_id' => $context->child_id,
                ]);
            DB::transaction(function () use ($candidate, $claim, $delivery, $providerMessageId): void {
                $current = DB::table('milestone_notification_deliveries')->where('id', $candidate->id)->where('claim_token', $claim)->first();
                if (! $current) {
                    return;
                }
                $acceptedAt = now();
                DB::table('milestone_notification_deliveries')->where('id', $candidate->id)->where('claim_token', $claim)->update([
                    'status' => 'accepted', 'accepted_at' => $acceptedAt, 'provider_message_id' => $providerMessageId,
                    'claim_token' => null, 'claimed_at' => null, 'retry_after' => null, 'updated_at' => $acceptedAt,
                ]);
                $field = (int) $delivery->days_before === 7 ? 'notified_7d_at' : 'notified_1d_at';
                DB::table('milestone_reminders')->where('id', $delivery->milestone_reminder_id)->whereNull($field)->update([
                    $field => $acceptedAt, 'notified_at' => $acceptedAt, 'status' => 'send_accepted', 'updated_at' => $acceptedAt,
                ]);
            }, 3);
            $sent++;
        } catch (InvalidFcmTokenException) {
            DB::table('device_tokens')->where('id', $delivery->device_token_id)->delete();
            $this->finish($candidate->id, $claim, 'failed', 'invalid_device_token');
            $failed++;
        } catch (FcmDeliveryUnknownException) {
            // The provider may have accepted the message before the connection was lost.
            // Do not retry an ambiguous send, which could duplicate a personal notification.
            $this->finish($candidate->id, $claim, 'unknown', 'provider_result_unknown');
            $failed++;
        } catch (FcmPermanentException) {
            $this->finish($candidate->id, $claim, 'failed', 'provider_rejected');
            $failed++;
        } catch (\Throwable $exception) {
            $attempts = (int) $delivery->attempt_count;
            $terminal = $attempts >= self::MAX_ATTEMPTS;
            DB::table('milestone_notification_deliveries')->where('id', $candidate->id)->where('claim_token', $claim)->update([
                'status' => $terminal ? 'failed' : 'pending', 'reason_code' => 'provider_error',
                'claim_token' => null, 'claimed_at' => null, 'retry_after' => $terminal ? null : now()->addMinutes(15 * $attempts), 'updated_at' => now(),
            ]);
            report(new RuntimeException('Milestone reminder delivery failed.'));
            $failed++;
        }
    }

    private function finish(int $id, string $claim, string $status, string $reason): void
    {
        DB::table('milestone_notification_deliveries')->where('id', $id)->where('claim_token', $claim)->update([
            'status' => $status, 'reason_code' => $reason, 'claim_token' => null, 'claimed_at' => null, 'retry_after' => null, 'updated_at' => now(),
        ]);
    }
}
