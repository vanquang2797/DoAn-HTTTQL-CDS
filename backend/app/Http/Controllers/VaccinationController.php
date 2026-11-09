<?php

namespace App\Http\Controllers;

use App\Services\FamilyAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VaccinationController extends Controller
{
    public function __construct(private FamilyAccess $access) {}

    public function index(Request $r, int $id)
    {
        $this->access->child($r->user(), $id);
        $r->validate(['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100']);
        $schedules = DB::table('vaccination_schedules')->join('vaccine_templates', 'vaccine_templates.id', '=', 'vaccination_schedules.vaccine_template_id')->where('child_profile_id', $id)
            ->select('vaccination_schedules.*', 'vaccine_templates.name', 'vaccine_templates.dose_number', 'vaccine_templates.schedule_version', 'vaccine_templates.timing_note')->orderBy('vaccination_schedules.id')->paginate($r->integer('per_page', 20));
        $today = CarbonImmutable::today(config('babi.calendar_timezone'));
        $schedules->through(function ($item) use ($today) {
            $item->effective_date = $item->appointment_date ?? $item->due_date;
            $item->date_basis = $item->appointment_date ? 'appointment' : ($item->due_date ? 'reference' : null);
            $item->date_label = ! $item->effective_date ? 'needs_appointment' : ($item->effective_date < $today->toDateString() ? 'date_passed' : ($item->effective_date <= $today->addDays(7)->toDateString() ? 'upcoming' : 'future'));

            return $item;
        });

        return response()->json(['success' => true, 'data' => $schedules]);
    }

    public function appointment(Request $r, int $id)
    {
        $data = $r->validate(['appointment_date' => 'present|nullable|date_format:Y-m-d', 'appointment_source' => 'nullable|required_with:appointment_date|string|max:255', 'expected_data_revision' => 'required|integer|min:0']);

        return $this->changeSchedule($r, $id, $data, false);
    }

    public function pending(Request $r, int $id)
    {
        $data = $r->validate(['confirmed' => 'required|accepted', 'expected_data_revision' => 'required|integer|min:0']);

        return $this->changeSchedule($r, $id, $data, true);
    }

    private function changeSchedule(Request $r, int $id, array $data, bool $pending)
    {
        DB::transaction(function () use ($r, $id, $data, $pending) {
            $schedule = DB::table('vaccination_schedules')->find($id);
            abort_unless($schedule, 403, 'Không có quyền truy cập.');
            $child = $this->access->child($r->user(), $schedule->child_profile_id, true, true, true);
            $schedule = DB::table('vaccination_schedules')->where('id', $id)->lockForUpdate()->first();
            abort_unless($schedule && $child->data_revision === (int) $data['expected_data_revision'], 409, 'Dữ liệu đã thay đổi.');
            abort_if(DB::table('vaccination_records')->where('vaccination_schedule_id', $id)->where('status', 'active')->exists(), 409, 'Lịch đã có lần tiêm còn hiệu lực.');
            if ($pending) {
                $changes = ['status' => 'pending'];
            } else {
                abort_if($data['appointment_date'] && $data['appointment_date'] < $child->date_of_birth->toDateString(), 422, 'Ngày hẹn không thể trước ngày sinh.');
                $changes = ['appointment_date' => $data['appointment_date'], 'appointment_source' => $data['appointment_date'] ? ($data['appointment_source'] ?? null) : null];
                if (($schedule->appointment_date ?? $schedule->due_date) !== ($data['appointment_date'] ?? $schedule->due_date)) {
                    $changes += ['notified_7d_at' => null, 'notified_1d_at' => null];
                }
            }
            DB::table('vaccination_schedules')->where('id', $id)->update($changes + ['updated_at' => now()]);
            $child->increment('data_revision');
        }, 3);

        return response()->json(['success' => true, 'data' => DB::table('vaccination_schedules')->find($id)]);
    }

    public function records(Request $r, int $id)
    {
        $this->access->child($r->user(), $id);
        $r->validate(['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100']);

        return response()->json(['success' => true, 'data' => DB::table('vaccination_records')->where('child_profile_id', $id)->orderByDesc('administered_date')->orderByDesc('id')->paginate($r->integer('per_page', 20))]);
    }

    private function recordRules(): array
    {
        return ['vaccine_name' => 'required|string|max:150', 'dose_number' => 'nullable|integer|min:1|max:65535', 'date_precision' => 'required|in:exact,unknown', 'administered_date' => 'nullable|required_if:date_precision,exact|prohibited_if:date_precision,unknown|date_format:Y-m-d', 'vaccination_schedule_id' => 'present|nullable|integer', 'facility' => 'nullable|string|max:255', 'source' => 'required|in:document,parent_report', 'notes' => 'nullable|string|max:5000', 'expected_data_revision' => 'required|integer|min:0'];
    }

    public function storeRecord(Request $r, int $id)
    {
        $data = $r->validate($this->recordRules());
        $recordId = DB::transaction(function () use ($r, $id, $data) {
            $child = $this->access->child($r->user(), $id, true, true, true);
            abort_unless($child->data_revision === (int) $data['expected_data_revision'], 409, 'Dữ liệu đã thay đổi.');
            $this->validateDate($data, $child->date_of_birth->toDateString());
            $this->lockSchedules($id, [$data['vaccination_schedule_id']]);
            if ($data['vaccination_schedule_id']) {
                abort_if(DB::table('vaccination_records')->where('vaccination_schedule_id', $data['vaccination_schedule_id'])->where('status', 'active')->exists(), 409, 'Lịch đã có lần tiêm còn hiệu lực.');
            }
            unset($data['expected_data_revision']);
            $recordId = DB::table('vaccination_records')->insertGetId($data + ['child_profile_id' => $id, 'recorded_by' => $r->user()->id, 'status' => 'active', 'version' => 0, 'created_at' => now(), 'updated_at' => now()]);
            $record = DB::table('vaccination_records')->find($recordId);
            $this->audit($recordId, $r->user()->id, 'create', 'Ghi nhận lần tiêm.', null, $record);
            $this->syncSchedule($data['vaccination_schedule_id']);
            $child->increment('data_revision');

            return $recordId;
        }, 3);

        return response()->json(['success' => true, 'data' => DB::table('vaccination_records')->find($recordId)], 201);
    }

    public function correct(Request $r, int $id)
    {
        $r->validate(['action' => 'required|in:correct,void']);
        $void = $r->input('action') === 'void';
        $rules = ['action' => 'required|in:correct,void', 'reason' => 'required|string|min:1|max:500', 'expected_version' => 'required|integer|min:0', 'expected_data_revision' => 'required|integer|min:0'];
        $data = $r->validate($void ? $rules : $rules + $this->recordRules());
        DB::transaction(function () use ($r, $id, $data, $void) {
            $before = DB::table('vaccination_records')->find($id);
            abort_unless($before, 403, 'Không có quyền truy cập.');
            $child = $this->access->child($r->user(), $before->child_profile_id, true, true, true);
            $before = DB::table('vaccination_records')->find($id);
            abort_unless($before && $before->status === 'active' && $before->version === (int) $data['expected_version'] && $child->data_revision === (int) $data['expected_data_revision'], 409, 'Dữ liệu đã thay đổi hoặc lần tiêm đã hủy.');
            $newSchedule = $void ? $before->vaccination_schedule_id : $data['vaccination_schedule_id'];
            $this->lockSchedules($child->id, [$before->vaccination_schedule_id, $newSchedule]);
            DB::table('vaccination_records')->where('id', $id)->lockForUpdate()->first();
            if (! $void) {
                $this->validateDate($data, $child->date_of_birth->toDateString());
                abort_if($newSchedule && DB::table('vaccination_records')->where('vaccination_schedule_id', $newSchedule)->where('status', 'active')->where('id', '!=', $id)->exists(), 409, 'Lịch đã có lần tiêm còn hiệu lực khác.');
            }
            $changes = $void ? ['status' => 'voided'] : collect($data)->except(['action', 'reason', 'expected_version', 'expected_data_revision'])->all();
            DB::table('vaccination_records')->where('id', $id)->update($changes + ['version' => $before->version + 1, 'updated_at' => now()]);
            $this->audit($id, $r->user()->id, $void ? 'void' : 'correct', $data['reason'], $before, DB::table('vaccination_records')->find($id));
            foreach (array_unique([$before->vaccination_schedule_id, $newSchedule]) as $scheduleId) {
                $this->syncSchedule($scheduleId);
            }
            $child->increment('data_revision');
        }, 3);

        return response()->json(['success' => true, 'data' => DB::table('vaccination_records')->find($id)]);
    }

    public function voidRecord(Request $r, int $id)
    {
        $r->merge(['action' => 'void']);

        return $this->correct($r, $id);
    }

    public function changes(Request $r, int $id)
    {
        $record = DB::table('vaccination_records')->find($id);
        abort_unless($record, 403, 'Không có quyền truy cập.');
        $this->access->child($r->user(), $record->child_profile_id, true);

        return response()->json(['success' => true, 'data' => DB::table('vaccination_record_changes')->where('vaccination_record_id', $id)->orderBy('id')->get()]);
    }

    private function validateDate(array &$data, string $birth): void
    {
        if ($data['date_precision'] === 'unknown') {
            $data['administered_date'] = null;
        } else {
            abort_if($data['administered_date'] < $birth || $data['administered_date'] > CarbonImmutable::today(config('babi.calendar_timezone'))->toDateString(), 422, 'Ngày tiêm phải từ ngày sinh đến hôm nay.');
        }
    }

    private function lockSchedules(int $childId, array $ids): void
    {
        foreach (DB::table('vaccination_schedules')->whereIn('id', array_filter($ids))->orderBy('id')->lockForUpdate()->get() as $schedule) {
            abort_unless($schedule->child_profile_id === $childId, 422, 'Lịch không thuộc hồ sơ này.');
        }
        abort_unless(DB::table('vaccination_schedules')->where('child_profile_id', $childId)->whereIn('id', array_filter($ids))->count() === count(array_unique(array_filter($ids))), 422, 'Lịch liên kết không hợp lệ.');
    }

    private function syncSchedule(?int $id): void
    {
        if ($id) {
            $active = DB::table('vaccination_records')->where('vaccination_schedule_id', $id)->where('status', 'active')->exists();
            DB::table('vaccination_schedules')->where('id', $id)->update(['status' => $active ? 'recorded' : 'unrecorded', 'updated_at' => now()]);
        }
    }

    private function audit(int $id, int $actor, string $action, string $reason, ?object $before, object $after): void
    {
        DB::table('vaccination_record_changes')->insert(['vaccination_record_id' => $id, 'changed_by' => $actor, 'action' => $action, 'reason' => $reason, 'before_data' => $before ? json_encode($before, JSON_THROW_ON_ERROR) : null, 'after_data' => json_encode($after, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
    }
}
