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

class SendMemoryNotifications extends Command
{
    private const MAX_ATTEMPTS = 3;

    protected $signature = 'babi:send-memory-notifications';

    protected $description = 'Send push notifications when a family memory is published';

    public function handle(FcmClient $fcm): int
    {
        $exhaustedClaims = DB::table('memory_notification_deliveries')
            ->whereNull('sent_at')->whereNull('failed_at')->whereNull('cancelled_at')->whereNull('unknown_at')
            ->where('attempt_count', '>=', self::MAX_ATTEMPTS)
            ->where(fn ($query) => $query->whereNull('claimed_at')->orWhere('claimed_at', '<=', now()->subMinutes(5)))
            ->update([
                'failed_at' => now(), 'reason_code' => 'retry_limit_reached',
                'claim_token' => null, 'claimed_at' => null, 'retry_after' => null, 'updated_at' => now(),
            ]);
        if ($exhaustedClaims > 0) {
            $this->warn("Marked {$exhaustedClaims} exhausted memory notification claim(s) as failed.");
        }

        if (! $fcm->isConfigured()) {
            if (Cache::add('babi:fcm:memory-configuration-warning', true, now()->addHour())) {
                Log::warning('Family memory push is paused because Firebase credentials are not configured.');
            }
            $this->warn('Firebase is not configured; family memory notifications remain queued.');

            return self::SUCCESS;
        }

        $sent = 0;
        $failures = 0;
        DB::table('memory_notification_deliveries')->whereNull('sent_at')->whereNull('failed_at')->whereNull('cancelled_at')
            ->whereNull('unknown_at')->where(fn ($query) => $query->whereNull('retry_after')->orWhere('retry_after', '<=', now()))
            ->orderBy('id')->chunkById(100, function ($deliveries) use ($fcm, &$sent, &$failures) {
                foreach ($deliveries as $delivery) {
                    $this->deliver($delivery, $fcm, $sent, $failures);
                }
            });
        $this->info("Accepted {$sent} family memory notification(s).".($failures ? " {$failures} delivery attempt(s) will retry." : ''));

        return $failures ? self::FAILURE : self::SUCCESS;
    }

    private function deliver(object $candidate, FcmClient $fcm, int &$sent, int &$failures): void
    {
        $claim = (string) Str::uuid();
        $claimed = DB::table('memory_notification_deliveries')->where('id', $candidate->id)
            ->where('attempt_count', '<', self::MAX_ATTEMPTS)
            ->whereNull('sent_at')->whereNull('failed_at')->whereNull('cancelled_at')->whereNull('unknown_at')
            ->where(fn ($query) => $query->whereNull('retry_after')->orWhere('retry_after', '<=', now()))
            ->where(fn ($query) => $query->whereNull('claimed_at')->orWhere('claimed_at', '<=', now()->subMinutes(5)))
            ->update(['claim_token' => $claim, 'claimed_at' => now(), 'attempt_count' => DB::raw('attempt_count + 1'), 'updated_at' => now()]);
        if ($claimed !== 1) {
            return;
        }

        try {
            $delivery = DB::table('memory_notification_deliveries')->where('id', $candidate->id)->first();
            $memory = $delivery ? DB::table('memories')->where('id', $delivery->memory_id)->first() : null;
            $child = $memory ? DB::table('child_profiles')->where('id', $memory->child_profile_id)->first() : null;
            $recipientId = $delivery?->user_id;
            $device = $delivery && $recipientId
                ? DB::table('device_tokens')->where('id', $delivery->device_token_id)->where('user_id', $recipientId)->first()
                : null;
            $member = $child && $recipientId && $memory->created_by !== $recipientId
                ? DB::table('family_members')->join('users', 'users.id', '=', 'family_members.user_id')
                    ->where('family_members.family_space_id', $child->family_space_id)
                    ->where('family_members.user_id', $recipientId)->where('users.status', 'active')->exists()
                : false;
            $consent = $child && DB::table('child_data_consents')->where('child_profile_id', $child->id)
                ->where('purpose', 'storage')->whereNull('withdrawn_at')->exists();
            $preference = $recipientId ? DB::table('notification_preferences')->where('user_id', $recipientId)->first() : null;
            $enabled = ! $preference || (bool) $preference->memory_enabled;

            if (! $memory || $memory->status !== 'published' || ! $member || ! $consent || ! $enabled || ! $device) {
                $this->cancel($candidate->id, $claim);

                return;
            }

            $author = DB::table('users')->where('id', $memory->created_by)->value('name') ?: 'Người thân';
            $fcm->send(Crypt::decryptString($device->fcm_token), 'Kỷ niệm mới', "{$author} vừa chia sẻ một kỷ niệm trong gia đình.", [
                'type' => 'family_memory',
                'memory_id' => $memory->id,
                'child_profile_id' => $child->id,
            ]);
            DB::table('memory_notification_deliveries')->where('id', $candidate->id)->where('claim_token', $claim)
                ->update(['sent_at' => now(), 'claim_token' => null, 'claimed_at' => null, 'updated_at' => now()]);
            $sent++;
        } catch (InvalidFcmTokenException) {
            DB::table('device_tokens')->where('id', $candidate->device_token_id)->delete();
            DB::table('memory_notification_deliveries')->where('id', $candidate->id)->where('claim_token', $claim)
                ->update(['failed_at' => now(), 'reason_code' => 'invalid_device_token', 'device_token_id' => null, 'claim_token' => null, 'claimed_at' => null, 'updated_at' => now()]);
        } catch (FcmDeliveryUnknownException) {
            DB::table('memory_notification_deliveries')->where('id', $candidate->id)->where('claim_token', $claim)
                ->update(['unknown_at' => now(), 'reason_code' => 'provider_result_unknown', 'claim_token' => null, 'claimed_at' => null, 'updated_at' => now()]);
            $failures++;
        } catch (FcmPermanentException) {
            DB::table('memory_notification_deliveries')->where('id', $candidate->id)->where('claim_token', $claim)
                ->update(['failed_at' => now(), 'reason_code' => 'provider_rejected', 'claim_token' => null, 'claimed_at' => null, 'updated_at' => now()]);
            $failures++;
        } catch (FcmRetryableException) {
            $this->retryOrFail($candidate, $claim, 'provider_temporary_error');
            report(new \RuntimeException('Family memory notification delivery failed.'));
            $failures++;
        } catch (\Throwable) {
            $this->retryOrFail($candidate, $claim, 'delivery_error');
            report(new \RuntimeException('Family memory notification delivery failed.'));
            $failures++;
        }
    }

    private function cancel(int $id, string $claim): void
    {
        DB::table('memory_notification_deliveries')->where('id', $id)->where('claim_token', $claim)
            ->update(['cancelled_at' => now(), 'claim_token' => null, 'claimed_at' => null, 'updated_at' => now()]);
    }

    private function retryOrFail(object $candidate, string $claim, string $reason): void
    {
        $attempt = (int) $candidate->attempt_count + 1;
        $terminal = $attempt >= self::MAX_ATTEMPTS;
        DB::table('memory_notification_deliveries')->where('id', $candidate->id)->where('claim_token', $claim)->update([
            'failed_at' => $terminal ? now() : null,
            'reason_code' => $terminal ? 'retry_limit_reached' : $reason,
            'claim_token' => null,
            'claimed_at' => null,
            'retry_after' => $terminal ? null : now()->addMinutes(15 * (2 ** ($attempt - 1))),
            'updated_at' => now(),
        ]);
    }
}
