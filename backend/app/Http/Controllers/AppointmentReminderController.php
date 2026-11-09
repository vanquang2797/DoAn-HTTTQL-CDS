<?php

namespace App\Http\Controllers;

use App\Services\FamilyAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AppointmentReminderController extends Controller
{
    public function __construct(private FamilyAccess $access) {}

    public function index(Request $request, int $childId)
    {
        $this->access->child($request->user(), $childId);
        $items = DB::table('appointment_reminders')->where('child_profile_id', $childId)
            ->orderBy('is_completed')->orderBy('remind_at')->orderBy('id')->get();

        return response()->json(['success' => true, 'data' => $items->map(fn ($item) => $this->present($item))]);
    }

    public function store(Request $request, int $childId)
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'type' => 'required|in:doctor_visit,medicine',
            'remind_at' => ['required', 'date_format:Y-m-d\\TH:i:sP'],
            'location' => 'nullable|string|max:500',
            'notes' => 'nullable|string|max:10000',
            'expected_data_revision' => 'required|integer|min:0',
        ]);
        $when = Carbon::parse($data['remind_at'])->utc();
        abort_if($when->lt(now('UTC')), 422, 'Thời điểm nhắc phải ở hiện tại hoặc tương lai theo giờ Việt Nam.');

        $id = DB::transaction(function () use ($request, $childId, $data, $when) {
            $child = $this->access->child($request->user(), $childId, true, true, true);
            abort_unless($child->data_revision === (int) $data['expected_data_revision'], 409, 'Dữ liệu đã thay đổi. Vui lòng tải lại.');
            unset($data['expected_data_revision']);
            $id = DB::table('appointment_reminders')->insertGetId([
                'child_profile_id' => $child->id,
                'created_by' => $request->user()->id,
                'title' => trim($data['title']),
                'type' => $data['type'],
                'remind_at' => $when->format('Y-m-d H:i:s'),
                'location' => isset($data['location']) ? trim($data['location']) : null,
                'notes' => $data['notes'] ?? null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $child->increment('data_revision');

            return $id;
        }, 3);

        return response()->json(['success' => true, 'data' => $this->present(DB::table('appointment_reminders')->find($id))], 201);
    }

    public function update(Request $request, int $id)
    {
        $data = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'type' => 'sometimes|required|in:doctor_visit,medicine',
            'remind_at' => ['sometimes', 'required', 'date_format:Y-m-d\\TH:i:sP'],
            'location' => 'sometimes|nullable|string|max:500',
            'notes' => 'sometimes|nullable|string|max:10000',
            'is_completed' => 'sometimes|boolean',
            'expected_data_revision' => 'required|integer|min:0',
        ]);
        $expectedRevision = (int) $data['expected_data_revision'];
        unset($data['expected_data_revision']);
        abort_if($data === [], 422, 'Không có nội dung cần cập nhật.');

        $item = DB::transaction(function () use ($request, $id, $data, $expectedRevision) {
            $before = DB::table('appointment_reminders')->find($id);
            abort_unless($before, 404, 'Không tìm thấy nhắc việc.');
            $child = $this->access->child($request->user(), $before->child_profile_id, true, true, true);
            abort_unless($child->data_revision === $expectedRevision, 409, 'Dữ liệu đã thay đổi. Vui lòng tải lại.');
            $current = DB::table('appointment_reminders')->where('id', $id)->lockForUpdate()->first();
            abort_unless($current && (int) $current->child_profile_id === (int) $child->id, 404, 'Không tìm thấy nhắc việc.');

            if ($current->is_completed) {
                abort_if(array_diff(array_keys($data), ['notes']) !== [], 409, 'Nhắc việc đã hoàn tất; chỉ có thể sửa ghi chú hoặc xóa.');
            }
            $updates = [];
            foreach (['title', 'type', 'location', 'notes', 'is_completed'] as $field) {
                if (array_key_exists($field, $data)) {
                    $updates[$field] = $field === 'title' && $data[$field] !== null ? trim($data[$field]) : $data[$field];
                }
            }
            if (array_key_exists('remind_at', $data)) {
                $when = Carbon::parse($data['remind_at'])->utc();
                abort_if($when->lt(now('UTC')), 422, 'Thời điểm nhắc phải ở hiện tại hoặc tương lai theo giờ Việt Nam.');
                $newTime = $when->format('Y-m-d H:i:s');
                if ($newTime !== $current->remind_at) {
                    $updates['remind_at'] = $newTime;
                    $updates['notified_at'] = null;
                    $updates['notification_revision'] = $current->notification_revision + 1;
                }
            }
            if ($updates !== []) {
                $updates['updated_at'] = now();
                DB::table('appointment_reminders')->where('id', $id)->update($updates);
                $child->increment('data_revision');
            }

            return DB::table('appointment_reminders')->find($id);
        }, 3);

        return response()->json(['success' => true, 'data' => $this->present($item)]);
    }

    public function destroy(Request $request, int $id)
    {
        $data = $request->validate([
            'confirmed' => 'required|accepted',
            'expected_data_revision' => 'required|integer|min:0',
        ]);
        DB::transaction(function () use ($request, $id, $data) {
            $before = DB::table('appointment_reminders')->find($id);
            abort_unless($before, 404, 'Không tìm thấy nhắc việc.');
            $child = $this->access->child($request->user(), $before->child_profile_id, true, true, true);
            abort_unless($child->data_revision === (int) $data['expected_data_revision'], 409, 'Dữ liệu đã thay đổi. Vui lòng tải lại.');
            $current = DB::table('appointment_reminders')->where('id', $id)->lockForUpdate()->first();
            abort_unless($current, 404, 'Không tìm thấy nhắc việc.');
            DB::table('appointment_reminders')->where('id', $id)->delete();
            $child->increment('data_revision');
        }, 3);

        return response()->json(['success' => true]);
    }

    private function present(object $item): array
    {
        $item->remind_at = Carbon::parse($item->remind_at, 'UTC')->setTimezone('Asia/Ho_Chi_Minh')->toIso8601String();
        $item->is_completed = (bool) $item->is_completed;
        $item->notification_revision = (int) $item->notification_revision;

        return (array) $item;
    }
}
