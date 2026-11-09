<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class OperationService
{
    // Must run inside the same transaction as the domain write.
    // Locking the actor serializes requests using the same actor/key.
    public function run(User $actor, ?string $key, string $type, array $payload, callable $write, callable $authorizeReplay, string $resourceType = 'child_profile'): array
    {
        Validator::make(['operation_key' => $key], ['operation_key' => 'required|uuid'])->validate();
        $key = strtolower($key);
        User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
        $sort = function (array $value) use (&$sort): array {
            if (! array_is_list($value)) {
                ksort($value);
            }
            foreach ($value as &$item) {
                if (is_array($item)) {
                    $item = $sort($item);
                }
            }

            return $value;
        };
        $hash = hash('sha256', json_encode($sort($payload), JSON_THROW_ON_ERROR));
        $existing = DB::table('operation_requests')->where('actor_id', $actor->id)->where('operation_key', $key)->first();
        if ($existing) {
            abort_unless($existing->operation_type === $type && hash_equals($existing->payload_hash, $hash), 409, 'Mã thao tác đã được dùng cho nội dung khác.');
            abort_if(now()->greaterThanOrEqualTo($existing->expires_at), 409, 'Mã thao tác hết thời hạn. Kiểm tra dữ liệu trước khi tạo yêu cầu mới.');
            $authorizeReplay($existing);

            return ['id' => $existing->resource_id, 'replayed' => true];
        }
        $result = $write();
        DB::table('operation_requests')->insert([
            'actor_id' => $actor->id, 'operation_key' => $key, 'operation_type' => $type,
            'payload_hash' => $hash, 'status' => 'completed', 'resource_type' => $resourceType,
            'resource_id' => $result, 'expires_at' => now()->addDays(7), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['id' => $result, 'replayed' => false];
    }
}
