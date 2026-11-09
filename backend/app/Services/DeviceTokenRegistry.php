<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class DeviceTokenRegistry
{
    public function remove(int $userId, ?string $installationId = null): void
    {
        $tokens = DB::table('device_tokens')->where('user_id', $userId)
            ->when($installationId, fn ($query) => $query->where('installation_id', $installationId))
            ->pluck('id');
        if ($tokens->isEmpty()) {
            return;
        }

        DB::table('notification_deliveries')->whereIn('device_token_id', $tokens)
            ->whereNull('sent_at')->whereNull('failed_at')->whereNull('cancelled_at')
            ->update(['cancelled_at' => now(), 'device_token_id' => null, 'updated_at' => now()]);
        DB::table('memory_notification_deliveries')->whereIn('device_token_id', $tokens)
            ->whereNull('sent_at')->whereNull('failed_at')->whereNull('cancelled_at')
            ->update(['cancelled_at' => now(), 'device_token_id' => null, 'updated_at' => now()]);
        DB::table('vaccination_push_deliveries')->whereIn('device_token_id', $tokens)
            ->whereNull('sent_at')->whereNull('failed_at')->whereNull('cancelled_at')
            ->update(['cancelled_at' => now(), 'device_token_id' => null, 'updated_at' => now()]);
        DB::table('device_tokens')->whereIn('id', $tokens)->delete();
    }
}
