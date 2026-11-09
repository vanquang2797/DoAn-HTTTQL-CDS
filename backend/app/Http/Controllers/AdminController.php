<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateMilestoneReminders;
use App\Models\User;
use App\Services\DeviceTokenRegistry;
use App\Services\VaccinationScheduleService;
use App\Services\VaccineCatalogImporter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    public function milestoneTemplates(Request $request)
    {
        $this->assertAdmin($request->user());

        return response()->json(['success' => true, 'data' => DB::table('milestone_templates')->orderBy('age_months')->orderBy('id')->get()]);
    }

    public function createMilestoneTemplate(Request $request)
    {
        $this->assertAdmin($request->user());
        $data = $request->validate([
            'name' => 'required|string|max:150|unique:milestone_templates,name',
            'age_months' => 'required|integer|min:1|max:60',
            'description' => 'nullable|string|max:10000',
            'icon_code' => 'sometimes|required|string|max:50',
            'is_recurring' => 'sometimes|boolean',
        ]);
        $result = DB::transaction(function () use ($request, $data): array {
            $this->assertAdmin(User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail());
            $id = DB::table('milestone_templates')->insertGetId($data + [
                'icon_code' => 'auto_awesome', 'is_recurring' => false, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $childIds = DB::table('child_profiles')->whereExists(fn ($query) => $query->selectRaw('1')->from('child_data_consents')
                ->whereColumn('child_data_consents.child_profile_id', 'child_profiles.id')->where('child_data_consents.purpose', 'storage')
                ->whereNull('child_data_consents.withdrawn_at'))->pluck('id')->all();

            return ['id' => $id, 'child_ids' => $childIds];
        }, 3);
        foreach ($result['child_ids'] as $childId) {
            try {
                GenerateMilestoneReminders::dispatch((int) $childId);
            } catch (\Throwable $exception) {
                // The daily idempotent sync command will recover new milestone templates.
                report($exception);
            }
        }

        return response()->json(['success' => true, 'data' => DB::table('milestone_templates')->find($result['id'])], 201);
    }

    public function updateMilestoneTemplate(Request $request, int $id)
    {
        $this->assertAdmin($request->user());
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:150|unique:milestone_templates,name,'.$id,
            'age_months' => 'sometimes|required|integer|min:1|max:60',
            'description' => 'sometimes|nullable|string|max:10000',
            'icon_code' => 'sometimes|required|string|max:50',
            'is_recurring' => 'sometimes|boolean',
        ]);
        abort_if($data === [], 422, 'Không có nội dung cần cập nhật.');
        $template = DB::transaction(function () use ($request, $id, $data) {
            $this->assertAdmin(User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail());
            $current = DB::table('milestone_templates')->where('id', $id)->lockForUpdate()->first();
            abort_unless($current, 404, 'Không tìm thấy mẫu cột mốc.');
            $scheduleChanged = (array_key_exists('age_months', $data) && (int) $data['age_months'] !== (int) $current->age_months)
                || (array_key_exists('is_recurring', $data) && (bool) $data['is_recurring'] !== (bool) $current->is_recurring);
            abort_if($scheduleChanged && DB::table('milestone_reminders')->where('milestone_template_id', $id)->exists(), 409,
                'Không thể đổi tuổi hoặc chu kỳ của mẫu đã có cột mốc; hãy tạo mẫu mới để giữ lịch sử.');
            DB::table('milestone_templates')->where('id', $id)->update($data + ['updated_at' => now()]);

            return DB::table('milestone_templates')->find($id);
        }, 3);

        return response()->json(['success' => true, 'data' => $template]);
    }

    public function importVaccineCatalog(Request $request, VaccineCatalogImporter $importer)
    {
        $this->assertAdmin($request->user());
        $data = $request->validate([
            'reviewed' => 'required|accepted',
            'schedule_version' => 'required|string|max:80',
            'program' => 'required|in:expanded,service',
            'effective_until' => 'nullable|date_format:Y-m-d',
            'templates' => 'required|array|min:1',
        ]);
        unset($data['reviewed']);

        return response()->json(['success' => true, 'data' => $importer->import($data)], 201);
    }

    public function vaccineCatalogs(Request $request, VaccinationScheduleService $catalog)
    {
        $this->assertAdmin($request->user());
        $currentVersion = $catalog->currentVersion();
        $versions = DB::table('vaccine_templates')
            ->select('schedule_version', 'program')
            ->selectRaw('COUNT(*) AS template_count')
            ->selectRaw('SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active_template_count')
            ->selectRaw('MAX(effective_until) AS effective_until')
            ->groupBy('schedule_version', 'program')->orderBy('program')->orderByDesc('schedule_version')->get()
            ->map(fn ($version) => [
                'schedule_version' => $version->schedule_version,
                'program' => $version->program,
                'template_count' => (int) $version->template_count,
                'active_template_count' => (int) $version->active_template_count,
                'effective_until' => $version->effective_until,
                'is_current' => $version->program === 'expanded' && $version->schedule_version === $currentVersion,
            ]);

        return response()->json(['success' => true, 'data' => $versions]);
    }

    public function activateVaccineCatalog(Request $request, VaccinationScheduleService $catalog)
    {
        $data = $request->validate(['schedule_version' => 'required|string|max:80']);
        $this->assertAdmin($request->user());
        $templates = DB::table('vaccine_templates')->where('program', 'expanded')
            ->where('schedule_version', $data['schedule_version'])->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', today(config('babi.calendar_timezone'))))
            ->orderBy('id')->get();
        abort_if($templates->isEmpty(), 422, 'Không tìm thấy phiên bản danh mục mở rộng đang có hiệu lực.');
        foreach ($templates as $template) {
            abort_if(! filter_var($template->source_url, FILTER_VALIDATE_URL)
                || ($template->timing_rule === 'age' && ($template->age_value === null || ! in_array($template->age_unit, ['day', 'month'], true)))
                || ($template->timing_rule === 'clinical' && ($template->age_value !== null || $template->age_unit !== null)), 422, 'Phiên bản danh mục có mẫu không hợp lệ.');
        }

        DB::transaction(function () use ($request, $data) {
            $this->assertAdmin(User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail());
            DB::table('vaccine_catalog_settings')->insertOrIgnore([
                'setting_key' => 'expanded', 'schedule_version' => $data['schedule_version'],
                'updated_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('vaccine_catalog_settings')->where('setting_key', 'expanded')->update([
                'schedule_version' => $data['schedule_version'], 'updated_by' => $request->user()->id, 'updated_at' => now(),
            ]);
        }, 3);

        return response()->json(['success' => true, 'data' => [
            'schedule_version' => $catalog->currentVersion(), 'vaccine_catalog_ready' => $catalog->currentTemplates()->isNotEmpty(),
        ]]);
    }

    public function updateUserStatus(Request $request, int $id, DeviceTokenRegistry $devices)
    {
        $data = $request->validate(['status' => 'required|in:active,deactivated']);
        $target = DB::transaction(function () use ($request, $id, $data, $devices) {
            $actor = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $this->assertAdmin($actor);

            $user = User::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($data['status'] === 'deactivated' && $user->status !== 'deactivated') {
                $ownedSpaces = DB::table('family_members')->where('user_id', $user->id)->where('role', 'owner')->pluck('family_space_id');
                foreach ($ownedSpaces as $spaceId) {
                    DB::table('family_spaces')->where('id', $spaceId)->lockForUpdate()->first();
                    $otherActiveOwners = DB::table('family_members')
                        ->join('users', 'users.id', '=', 'family_members.user_id')
                        ->where('family_members.family_space_id', $spaceId)
                        ->where('family_members.role', 'owner')
                        ->where('users.status', 'active')
                        ->where('users.id', '<>', $user->id)
                        ->exists();
                    abort_unless($otherActiveOwners, 409, 'Không thể ngừng tài khoản Owner hoạt động cuối cùng của gia đình.');
                }
            }

            $user->status = $data['status'];
            $user->save();
            if ($data['status'] === 'deactivated') {
                $user->tokens()->delete();
                $devices->remove($user->id);
            }

            return $user->only(['id', 'name', 'email', 'status']);
        }, 3);

        return response()->json(['success' => true, 'data' => $target]);
    }

    private function assertAdmin(?User $user): void
    {
        abort_unless($user && $user->is_admin && $user->status === 'active', 403, 'Chỉ Admin đang hoạt động mới được quản lý hệ thống.');
    }
}
