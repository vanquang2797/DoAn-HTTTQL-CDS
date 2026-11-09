<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\FamilyAccess;
use App\Services\PrivateMedia;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProcessMemory implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 80;

    public string $claimToken;

    public function __construct(public int $memoryId, public int $generation, ?string $claimToken = null)
    {
        $this->claimToken = $claimToken ?? (string) Str::uuid();
    }

    public function backoff(): array
    {
        return [10, 30, 90];
    }

    public function handle(FamilyAccess $access, PrivateMedia $storage): void
    {
        $claimed = DB::table('memories')->where('id', $this->memoryId)
            ->where('status', 'uploading')->where('processing_generation', $this->generation)
            ->where(fn ($query) => $query->whereNull('processing_claimed_at')->orWhere('processing_claimed_at', '<=', now()->subMinutes(2)))
            ->update(['processing_claim_token' => $this->claimToken, 'processing_claimed_at' => now(), 'updated_at' => now()]);
        if ($claimed !== 1) {
            return;
        }
        $memory = DB::table('memories')->find($this->memoryId);
        $outputs = [];
        try {
            $user = User::find($memory->created_by);
            if (! $user) {
                $this->releaseClaim();

                return;
            }
            $access->child($user, $memory->child_profile_id);
            foreach (json_decode($memory->source_manifest, true, 512, JSON_THROW_ON_ERROR) as $source) {
                $outputs[] = $storage->process($source);
            }
            if (! $outputs) {
                throw new \RuntimeException('MEDIA_MISSING');
            }
            $sourcePaths = array_column(json_decode($memory->source_manifest, true, 512, JSON_THROW_ON_ERROR), 'path');
            $published = DB::transaction(function () use ($access, $memory, $user, $outputs, $sourcePaths, $storage) {
                $child = $access->child($user->fresh(), $memory->child_profile_id, false, true, true);
                $current = DB::table('memories')->where('id', $this->memoryId)->lockForUpdate()->first();
                if (! $current || $current->status !== 'uploading' || $current->processing_generation !== $this->generation || $current->processing_claim_token !== $this->claimToken) {
                    return false;
                }
                foreach ($outputs as $output) {
                    DB::table('media_files')->insert($output + ['memory_id' => $this->memoryId, 'created_at' => now(), 'updated_at' => now()]);
                }
                DB::table('memories')->where('id', $this->memoryId)->update(['status' => 'published', 'processing_claim_token' => null, 'processing_claimed_at' => null, 'updated_at' => now()]);
                $child->increment('data_revision');
                $recipients = DB::table('family_members')
                    ->join('users', 'users.id', '=', 'family_members.user_id')
                    ->leftJoin('notification_preferences', 'notification_preferences.user_id', '=', 'users.id')
                    ->where('family_members.family_space_id', $child->family_space_id)
                    ->where('family_members.user_id', '<>', $memory->created_by)
                    ->where('users.status', 'active')
                    ->where(fn ($query) => $query->whereNull('notification_preferences.user_id')->orWhere('notification_preferences.memory_enabled', true))
                    ->orderBy('users.id')->pluck('users.id');
                foreach ($recipients as $recipientId) {
                    $deviceIds = DB::table('device_tokens')->where('user_id', $recipientId)->orderBy('id')->pluck('id');
                    foreach ($deviceIds as $deviceId) {
                        DB::table('memory_notification_deliveries')->insertOrIgnore([
                            'memory_id' => $this->memoryId,
                            'user_id' => $recipientId,
                            'device_token_id' => $deviceId,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
                $storage->cleanup($sourcePaths);

                return true;
            }, 3);
            if (! $published) {
                $storage->cleanup(array_column($outputs, 'file_path'));
            }
        } catch (\Throwable $e) {
            $storage->cleanup(array_column($outputs, 'file_path'));
            $this->releaseClaim();
            throw $e;
        }
    }

    private function releaseClaim(): void
    {
        DB::table('memories')->where('id', $this->memoryId)
            ->where('processing_generation', $this->generation)->where('processing_claim_token', $this->claimToken)
            ->update(['processing_claim_token' => null, 'processing_claimed_at' => null, 'updated_at' => now()]);
    }

    public function failed(?\Throwable $e): void
    {
        DB::transaction(function () {
            $memory = DB::table('memories')->find($this->memoryId);
            if (! $memory) {
                return;
            }
            $child = DB::table('child_profiles')->find($memory->child_profile_id);
            if (! $child) {
                return;
            }
            DB::table('family_spaces')->where('id', $child->family_space_id)->lockForUpdate()->first();
            DB::table('child_profiles')->where('id', $child->id)->lockForUpdate()->first();
            $current = DB::table('memories')->where('id', $this->memoryId)->lockForUpdate()->first();
            if (! $current || $current->status !== 'uploading' || $current->processing_generation !== $this->generation || ($current->processing_claim_token && $current->processing_claim_token !== $this->claimToken)) {
                return;
            }
            $changed = DB::table('memories')->where('id', $this->memoryId)->where('processing_generation', $this->generation)->where('status', 'uploading')->update(['status' => 'failed', 'processing_claim_token' => null, 'processing_claimed_at' => null, 'updated_at' => now()]);
            if ($changed) {
                DB::table('child_profiles')->where('id', $child->id)->increment('data_revision');
            }
        }, 3);
    }
}
