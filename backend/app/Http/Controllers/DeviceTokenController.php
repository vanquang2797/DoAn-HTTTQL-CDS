<?php

namespace App\Http\Controllers;

use App\Services\DeviceTokenRegistry;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class DeviceTokenController extends Controller
{
    public function __construct(private DeviceTokenRegistry $registry) {}

    public function store(Request $request)
    {
        $data = $request->validate([
            'fcm_token' => 'required|string|min:20|max:4096',
            'device_type' => 'required|in:android,ios',
            'installation_id' => 'required|uuid',
        ]);
        $tokenHash = hash('sha256', $data['fcm_token']);
        try {
            DB::transaction(function () use ($request, $data, $tokenHash) {
                $tokenOwner = DB::table('device_tokens')->where('token_hash', $tokenHash)->lockForUpdate()->first();
                abort_if($tokenOwner && $tokenOwner->installation_id !== $data['installation_id'], 409, 'Mã thiết bị đã được liên kết với một bản cài đặt khác.');

                $installation = DB::table('device_tokens')->where('installation_id', $data['installation_id'])->lockForUpdate()->first();
                $values = [
                    'user_id' => $request->user()->id,
                    'fcm_token' => Crypt::encryptString($data['fcm_token']),
                    'token_hash' => $tokenHash,
                    'device_type' => $data['device_type'],
                    'last_used_at' => now(),
                    'updated_at' => now(),
                ];
                if ($installation) {
                    if ((int) $installation->user_id !== (int) $request->user()->id) {
                        // A previous account's delivery history must not follow the installation.
                        DB::table('notification_deliveries')->where('device_token_id', $installation->id)
                            ->whereNull('sent_at')->whereNull('failed_at')->whereNull('cancelled_at')
                            ->update(['cancelled_at' => now(), 'device_token_id' => null, 'updated_at' => now()]);
                        DB::table('memory_notification_deliveries')->where('device_token_id', $installation->id)
                            ->whereNull('sent_at')->whereNull('failed_at')->whereNull('cancelled_at')
                            ->update(['cancelled_at' => now(), 'device_token_id' => null, 'updated_at' => now()]);
                        DB::table('vaccination_push_deliveries')->where('device_token_id', $installation->id)
                            ->whereNull('sent_at')->whereNull('failed_at')->whereNull('cancelled_at')
                            ->update(['cancelled_at' => now(), 'device_token_id' => null, 'updated_at' => now()]);
                        DB::table('device_tokens')->where('id', $installation->id)->delete();
                        $values['installation_id'] = $data['installation_id'];
                        $values['created_at'] = now();
                        DB::table('device_tokens')->insert($values);
                    } else {
                        DB::table('device_tokens')->where('id', $installation->id)->update($values);
                    }
                } else {
                    $values['installation_id'] = $data['installation_id'];
                    $values['created_at'] = now();
                    DB::table('device_tokens')->insert($values);
                }
            }, 3);
        } catch (UniqueConstraintViolationException) {
            abort(409, 'Mã thiết bị hoặc bản cài đặt vừa được liên kết với phiên khác. Hãy tải lại rồi thử lại.');
        }

        return response()->json(['success' => true, 'data' => ['registered' => true]]);
    }

    public function destroy(Request $request)
    {
        $data = $request->validate(['installation_id' => 'required|uuid']);
        DB::transaction(fn () => $this->registry->remove($request->user()->id, $data['installation_id']), 3);

        return response()->json(['success' => true]);
    }
}
