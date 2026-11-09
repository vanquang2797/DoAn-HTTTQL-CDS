<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotificationPreferenceController extends Controller
{
    private const FIELDS = ['vaccination_enabled', 'memory_enabled', 'milestone_enabled', 'appointment_enabled'];

    public function show(Request $request)
    {
        $preferences = DB::table('notification_preferences')->where('user_id', $request->user()->id)->first();

        return response()->json(['success' => true, 'data' => $this->values($preferences)]);
    }

    public function update(Request $request)
    {
        $data = $request->validate(array_fill_keys(self::FIELDS, 'sometimes|boolean'));
        abort_if($data === [], 422, 'Chọn ít nhất một tùy chọn thông báo.');
        $userId = $request->user()->id;
        DB::transaction(function () use ($userId, $data) {
            DB::table('notification_preferences')->insertOrIgnore([
                'user_id' => $userId,
                'vaccination_enabled' => true,
                'memory_enabled' => true,
                'milestone_enabled' => true,
                'appointment_enabled' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('notification_preferences')->where('user_id', $userId)->update($data + ['updated_at' => now()]);
        }, 3);

        return $this->show($request);
    }

    private function values(?object $preferences): array
    {
        $values = [];
        foreach (self::FIELDS as $field) {
            $values[$field] = $preferences ? (bool) $preferences->{$field} : true;
        }

        return $values;
    }
}
