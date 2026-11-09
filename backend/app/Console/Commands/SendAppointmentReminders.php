<?php

namespace App\Console\Commands;

use App\Services\FcmClient;
use App\Services\FcmDeliveryUnknownException;
use App\Services\FcmPermanentException;
use App\Services\FcmRetryableException;
use App\Services\InvalidFcmTokenException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SendAppointmentReminders extends Command
{
    private const MAX_ATTEMPTS = 3;

    protected $signature = 'babi:send-appointment-reminders';

    protected $description = 'Send due one-time appointment reminders to active Owner devices';

    public function handle(FcmClient $fcm): int
    {
        $exhaustedClaims = DB::table('notification_deliveries')
            ->whereNull('sent_at')->whereNull('failed_at')->whereNull('cancelled_at')->whereNull('unknown_at')
            ->where('attempt_count', '>=', self::MAX_ATTEMPTS)
            ->where(fn ($query) => $query->whereNull('claimed_at')->orWhere('claimed_at', '<=', now()->subMinutes(5)))
            ->update([
                'failed_at' => now(), 'reason_code' => 'retry_limit_reached',
                'claim_token' => null, 'claimed_at' => null, 'retry_after' => null, 'updated_at' => now(),
            ]);
        if ($exhaustedClaims > 0) {
            $this->warn("Marked {$exhaustedClaims} exhausted appointment delivery claim(s) as failed.");
        }

        if ($fcm->isConfigured() === false) {
            if (Cache::add('babi:fcm:configuration-warning', true, now()->addHour())) {
                Log::warning('Appointment reminder push is paused because Firebase credentials are not configured.');
            }
            $this->warn('Firebase is not configured; due appointment reminders remain queued.');

            return self::SUCCESS;
        }

        $sent = 0;
        $failures = 0;
        DB::table('appointment_reminders')->where('is_completed', false)->whereNull('notified_at')
            ->where('remind_at', '<=', now('UTC'))->orderBy('id')->chunkById(100, function ($reminders) use ($fcm, &$sent, &$failures) {
                foreach ($reminders as $reminder) {
                    $this->deliver($reminder, $fcm, $sent, $failures);
                }
            });
        $this->info("Accepted {$sent} appointment reminder notification(s).".($failures ? " {$failures} delivery attempt(s) will retry." : ''));

        return $failures ? self::FAILURE : self::SUCCESS;
    }

    private function deliver(object $candidate, FcmClient $fcm, int &$sent, int &$failures): void
    {
        $reminder = DB::table('appointment_reminders')->where('id', $candidate->id)->first();
        if (! $reminder || $reminder->is_completed || $reminder->notified_at || $reminder->remind_at > now('UTC')->toDateTimeString()) {
            return;
        }
        $child = DB::table('child_profiles')->where('id', $reminder->child_profile_id)->first();
        if (! $child || ! DB::table('child_data_consents')->where('child_profile_id', $child->id)->where('purpose', 'storage')->whereNull('withdrawn_at')->exists()) {
            return;
        }
        $owners = DB::table('family_members')->join('users', 'users.id', '=', 'family_members.user_id')
            ->where('family_members.family_space_id', $child->family_space_id)->where('family_members.role', 'owner')
            ->where('users.status', 'active')->orderBy('users.id')->get(['users.id']);
        if ($owners->isEmpty()) {
            return;
        }
        foreach ($owners as $owner) {
            $preference = DB::table('notification_preferences')->where('user_id', $owner->id)->first();
            if ($preference && ! $preference->appointment_enabled) {
                continue;
            }
            foreach (DB::table('device_tokens')->where('user_id', $owner->id)->orderBy('id')->get() as $device) {
                DB::table('notification_deliveries')->insertOrIgnore([
                    'appointment_reminder_id' => $reminder->id,
                    'notification_revision' => $reminder->notification_revision,
                    'user_id' => $owner->id,
                    'device_token_id' => $device->id,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        $deliveries = DB::table('notification_deliveries')
            ->where('appointment_reminder_id', $reminder->id)
            ->where('notification_revision', $reminder->notification_revision)
            ->where('attempt_count', '<', self::MAX_ATTEMPTS)
            ->whereNull('sent_at')->whereNull('failed_at')->whereNull('cancelled_at')->whereNull('unknown_at')
            ->where(fn ($query) => $query->whereNull('retry_after')->orWhere('retry_after', '<=', now()))
            ->orderBy('id')->get();
        foreach ($deliveries as $delivery) {
            $claim = (string) Str::uuid();
            $claimed = DB::table('notification_deliveries')->where('id', $delivery->id)
                ->where('attempt_count', '<', self::MAX_ATTEMPTS)
                ->whereNull('sent_at')->whereNull('failed_at')->whereNull('unknown_at')
                ->where(fn ($query) => $query->whereNull('retry_after')->orWhere('retry_after', '<=', now()))
                ->where(fn ($query) => $query->whereNull('claimed_at')->orWhere('claimed_at', '<=', now()->subMinutes(5)))
                ->update(['claim_token' => $claim, 'claimed_at' => now(), 'attempt_count' => DB::raw('attempt_count + 1'), 'updated_at' => now()]);
            if ($claimed !== 1) {
                continue;
            }

            try {
                $recipientId = $delivery->user_id;
                $current = DB::table('appointment_reminders')->where('id', $reminder->id)->first();
                $currentChild = $current ? DB::table('child_profiles')->where('id', $current->child_profile_id)->first() : null;
                $currentDevice = DB::table('device_tokens')->where('id', $delivery->device_token_id)->where('user_id', $recipientId)->first();
                $stillOwner = $currentChild && DB::table('family_members')->join('users', 'users.id', '=', 'family_members.user_id')
                    ->where('family_members.family_space_id', $currentChild->family_space_id)
                    ->where('family_members.user_id', $recipientId)->where('family_members.role', 'owner')
                    ->where('users.status', 'active')->exists();
                $storageConsent = $currentChild && DB::table('child_data_consents')->where('child_profile_id', $currentChild->id)->where('purpose', 'storage')->whereNull('withdrawn_at')->exists();
                $currentPreference = DB::table('notification_preferences')->where('user_id', $recipientId)->first();
                $preferenceAllows = ! $currentPreference || (bool) $currentPreference->appointment_enabled;
                $isCurrent = $current && ! $current->is_completed && ! $current->notified_at
                    && (int) $current->notification_revision === (int) $delivery->notification_revision
                    && $current->remind_at <= now('UTC')->toDateTimeString();

                if (! $isCurrent || ! $stillOwner || ! $storageConsent || ! $preferenceAllows || ! $currentDevice) {
                    $this->cancel($delivery->id, $claim);

                    continue;
                }

                $token = Crypt::decryptString($currentDevice->fcm_token);
                $body = $current->type === 'medicine'
                    ? "Đến giờ nhắc: {$current->title}. Đây là nhắc việc do gia đình nhập, không phải hướng dẫn liều dùng."
                    : "Đến giờ nhắc: {$current->title}.";
                $fcm->send($token, "Nhắc việc cho bé {$currentChild->name}", $body, [
                    'type' => 'appointment_reminder', 'reminder_id' => $current->id,
                ]);
                DB::table('notification_deliveries')->where('id', $delivery->id)->where('claim_token', $claim)
                    ->update(['sent_at' => now(), 'claim_token' => null, 'claimed_at' => null, 'updated_at' => now()]);
                $sent++;
            } catch (InvalidFcmTokenException) {
                DB::table('device_tokens')->where('id', $delivery->device_token_id)->delete();
                DB::table('notification_deliveries')->where('id', $delivery->id)->where('claim_token', $claim)
                    ->update(['failed_at' => now(), 'reason_code' => 'invalid_device_token', 'device_token_id' => null, 'claim_token' => null, 'claimed_at' => null, 'updated_at' => now()]);
            } catch (FcmDeliveryUnknownException) {
                DB::table('notification_deliveries')->where('id', $delivery->id)->where('claim_token', $claim)
                    ->update(['unknown_at' => now(), 'reason_code' => 'provider_result_unknown', 'claim_token' => null, 'claimed_at' => null, 'updated_at' => now()]);
                $failures++;
            } catch (FcmPermanentException) {
                DB::table('notification_deliveries')->where('id', $delivery->id)->where('claim_token', $claim)
                    ->update(['failed_at' => now(), 'reason_code' => 'provider_rejected', 'claim_token' => null, 'claimed_at' => null, 'updated_at' => now()]);
                $failures++;
            } catch (FcmRetryableException) {
                $this->retryOrFail($delivery->id, $claim, (int) $delivery->attempt_count + 1);
                report(new \RuntimeException('One-time appointment reminder delivery failed.'));
                $failures++;
            } catch (\Throwable) {
                $this->retryOrFail($delivery->id, $claim, (int) $delivery->attempt_count + 1, 'delivery_error');
                report(new \RuntimeException('One-time appointment reminder delivery failed.'));
                $failures++;
            }
        }

        $base = DB::table('appointment_reminders')->where('id', $reminder->id)->where('notification_revision', $reminder->notification_revision);
        $hasSent = DB::table('notification_deliveries')->where('appointment_reminder_id', $reminder->id)
            ->where('notification_revision', $reminder->notification_revision)->whereNotNull('sent_at')->exists();
        $hasPending = DB::table('notification_deliveries')->where('appointment_reminder_id', $reminder->id)
            ->where('notification_revision', $reminder->notification_revision)->whereNull('sent_at')->whereNull('failed_at')
            ->whereNull('cancelled_at')->whereNull('unknown_at')->exists();
        if ($hasSent && ! $hasPending) {
            $base->whereNull('notified_at')->update(['notified_at' => now(), 'updated_at' => now()]);
        }
    }

    private function retryOrFail(int $deliveryId, string $claim, int $attempt, string $reason = 'provider_temporary_error'): void
    {
        $terminal = $attempt >= self::MAX_ATTEMPTS;
        DB::table('notification_deliveries')->where('id', $deliveryId)->where('claim_token', $claim)->update([
            'failed_at' => $terminal ? now() : null,
            'reason_code' => $terminal ? 'retry_limit_reached' : $reason,
            'retry_after' => $terminal ? null : now()->addMinutes(15 * (2 ** ($attempt - 1))),
            'claim_token' => null, 'claimed_at' => null, 'updated_at' => now(),
        ]);
    }

    private function cancel(int $deliveryId, string $claim): void
    {
        DB::table('notification_deliveries')->where('id', $deliveryId)->where('claim_token', $claim)
            ->update(['cancelled_at' => now(), 'claim_token' => null, 'claimed_at' => null, 'updated_at' => now()]);
    }
}
