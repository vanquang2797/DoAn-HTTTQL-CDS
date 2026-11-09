<?php

namespace App\Http\Controllers;

use App\Models\ChildProfile;
use App\Services\FamilyAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HealthRecordController extends Controller
{
    public function __construct(private FamilyAccess $access) {}

    public function index(Request $r, int $id)
    {
        $this->access->child($r->user(), $id);
        $r->validate(['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100']);
        $records = DB::table('health_records')->where('child_profile_id', $id)->orderBy('measured_at')->orderBy('id')->paginate($r->integer('per_page', 20));

        return response()->json(['success' => true, 'data' => $records, 'who_reference' => null, 'reference_unavailable_reason' => 'Chưa nạp và kiểm chứng bộ WHO LMS chính thức.']);
    }

    private function rules(): array
    {
        return ['measured_at' => 'required|date_format:Y-m-d', 'height_cm' => 'required|numeric|gt:0|max:999.9|decimal:0,1', 'weight_kg' => 'required|numeric|gt:0|max:999.99|decimal:0,2', 'measurement_method' => 'required|in:lying,standing', 'notes' => 'nullable|string|max:5000', 'outlier_confirmed' => 'sometimes|boolean'];
    }

    private function prepare(array $data, ChildProfile $child): array
    {
        $date = CarbonImmutable::parse($data['measured_at'], config('babi.calendar_timezone'));
        abort_if($data['measured_at'] < $child->date_of_birth->toDateString() || $date->greaterThan(CarbonImmutable::today(config('babi.calendar_timezone'))), 422, 'Ngày đo phải từ ngày sinh đến hôm nay.');
        $outlier = $data['height_cm'] < 30 || $data['height_cm'] > 120 || $data['weight_kg'] < 0.5 || $data['weight_kg'] > 30;
        abort_if($outlier && ! ($data['outlier_confirmed'] ?? false), 422, 'Số đo ngoài khoảng kiểm tra nhập liệu. Kiểm tra và gửi outlier_confirmed=true nếu đúng.');
        unset($data['outlier_confirmed'], $data['expected_version'], $data['expected_data_revision'], $data['child_profile_id']);

        return $data + ['age_days' => (int) $child->date_of_birth->diffInDays($date), 'outlier_confirmed_at' => $outlier ? now() : null];
    }

    public function store(Request $r)
    {
        $data = $r->validate($this->rules() + ['child_profile_id' => 'required|integer', 'expected_data_revision' => 'required|integer|min:0']);
        $id = DB::transaction(function () use ($r, $data) {
            $child = $this->access->child($r->user(), $data['child_profile_id'], true, true, true);
            abort_unless($child->data_revision === (int) $data['expected_data_revision'], 409, 'Dữ liệu đã thay đổi. Vui lòng tải lại.');
            $values = $this->prepare($data, $child);
            abort_if(DB::table('health_records')->where('child_profile_id', $child->id)->where('measured_at', $data['measured_at'])->exists(), 409, 'Ngày này đã có số đo. Hãy chọn cập nhật bản ghi đó.');
            $id = DB::table('health_records')->insertGetId($values + ['child_profile_id' => $child->id, 'version' => 0, 'created_at' => now(), 'updated_at' => now()]);
            $child->increment('data_revision');

            return $id;
        }, 3);

        return response()->json(['success' => true, 'data' => DB::table('health_records')->find($id)], 201);
    }

    public function update(Request $r, int $id)
    {
        $data = $r->validate($this->rules() + ['expected_version' => 'required|integer|min:0', 'expected_data_revision' => 'required|integer|min:0']);
        DB::transaction(function () use ($r, $id, $data) {
            $record = DB::table('health_records')->find($id);
            abort_unless($record, 403, 'Không có quyền truy cập.');
            $child = $this->access->child($r->user(), $record->child_profile_id, true, true, true);
            $record = DB::table('health_records')->where('id', $id)->lockForUpdate()->first();
            abort_unless($record && $record->version === (int) $data['expected_version'] && $child->data_revision === (int) $data['expected_data_revision'], 409, 'Dữ liệu đã thay đổi. Vui lòng tải lại.');
            abort_if(DB::table('health_records')->where('child_profile_id', $child->id)->where('measured_at', $data['measured_at'])->where('id', '!=', $id)->exists(), 409, 'Ngày này đã có số đo khác.');
            DB::table('health_records')->where('id', $id)->update($this->prepare($data, $child) + ['version' => $record->version + 1, 'updated_at' => now()]);
            $child->increment('data_revision');
        }, 3);

        return response()->json(['success' => true, 'data' => DB::table('health_records')->find($id)]);
    }

    public function destroy(Request $r, int $id)
    {
        $data = $r->validate(['expected_version' => 'required|integer|min:0', 'expected_data_revision' => 'required|integer|min:0', 'confirmed' => 'required|accepted']);
        DB::transaction(function () use ($r, $id, $data) {
            $record = DB::table('health_records')->find($id);
            abort_unless($record, 403, 'Không có quyền truy cập.');
            $child = $this->access->child($r->user(), $record->child_profile_id, true, true, true);
            $record = DB::table('health_records')->where('id', $id)->lockForUpdate()->first();
            abort_unless($record && $record->version === (int) $data['expected_version'] && $child->data_revision === (int) $data['expected_data_revision'], 409, 'Dữ liệu đã thay đổi.');
            DB::table('health_records')->where('id', $id)->delete();
            $child->increment('data_revision');
        });

        return response()->json(['success' => true]);
    }
}
