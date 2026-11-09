<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateMilestoneReminders;
use App\Models\ChildProfile;
use App\Services\FamilyAccess;
use App\Services\MilestoneReminderService;
use App\Services\OperationService;
use App\Services\PrivateMedia;
use App\Services\VaccinationScheduleService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ChildProfileController extends Controller
{
    public function __construct(private FamilyAccess $access, private VaccinationScheduleService $schedules, private OperationService $operations, private MilestoneReminderService $milestones) {}

    public function index(Request $r)
    {
        $spaces = DB::table('family_members')->where('user_id', $r->user()->id)->pluck('family_space_id');
        $ownedSpaceId = DB::table('family_members')->where('user_id', $r->user()->id)->where('role', 'owner')->value('family_space_id');
        if ($ownedSpaceId !== null) {
            $spaces = collect([$ownedSpaceId]);
        }
        $children = ChildProfile::whereIn('family_space_id', $spaces)->whereExists(function ($q) {
            $q->selectRaw('1')->from('child_data_consents')->whereColumn('child_profile_id', 'child_profiles.id')->where('purpose', 'storage')->whereNull('withdrawn_at');
        })->orderBy('id')->get();

        return response()->json(['success' => true, 'data' => $children]);
    }

    public function show(Request $r, int $id)
    {
        return response()->json(['success' => true, 'data' => $this->access->child($r->user(), $id)]);
    }

    public function avatar(Request $r, int $id)
    {
        $child = $this->access->child($r->user(), $id);
        abort_unless($child->avatar_path && Storage::disk('local')->exists($child->avatar_path), 404, 'Chưa có ảnh đại diện.');

        return response(Storage::disk('local')->get($child->avatar_path), 200, [
            'Content-Type' => $child->avatar_mime,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function profileRules(): array
    {
        return ['name' => 'required|string|max:100', 'date_of_birth' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.CarbonImmutable::today(config('babi.calendar_timezone'))->toDateString()], 'gender' => 'required|in:male,female', 'blood_type' => 'nullable|in:A+,A-,B+,B-,AB+,AB-,O+,O-,A,B,AB,O', 'birth_weight_kg' => 'nullable|numeric|min:0.1|max:20', 'birth_length_cm' => 'nullable|numeric|min:10|max:100'];
    }

    public function store(Request $r)
    {
        $data = $r->validate($this->profileRules() + [
            'representative_confirmed' => 'required|accepted', 'storage_consent' => 'required|accepted',
            'family_sharing_acknowledged' => 'required|accepted',
            'policy_version' => ['required', Rule::in([config('babi.policy_version')])],
            'family_space_id' => 'prohibited', 'user_id' => 'prohibited',
            'avatar' => 'sometimes|nullable|file|image|mimetypes:image/jpeg,image/png|max:10240',
        ]);
        $avatar = $r->file('avatar');
        unset($data['avatar']);
        if ($avatar) {
            $data['avatar_sha256'] = hash_file('sha256', $avatar->getRealPath());
        }
        $birth = CarbonImmutable::parse($data['date_of_birth'], config('babi.calendar_timezone'));
        abort_if(CarbonImmutable::today(config('babi.calendar_timezone'))->greaterThan($birth->addMonthsNoOverflow(60)), 422, 'Chỉ tạo mới hồ sơ trong phạm vi 60 tháng tuổi.');
        $avatarPaths = [];
        try {
            $result = DB::transaction(fn () => $this->operations->run($r->user(), $r->header('X-Operation-Key'), 'child.create', $data, function () use ($r, $data, $avatar, &$avatarPaths) {
                $member = DB::table('family_members')->where('user_id', $r->user()->id)->where('role', 'owner')->first();
                abort_if(
                    ! $member && DB::table('family_members')->where('user_id', $r->user()->id)->exists(),
                    409,
                    'Người thân đang tham gia Family Space khác nên không thể tạo Family Space Owner mới.'
                );
                if ($member) {
                    $spaceId = $member->family_space_id;
                    DB::table('family_spaces')->where('id', $spaceId)->lockForUpdate()->first();
                    $this->access->space($r->user(), $spaceId, true);
                } else {
                    $spaceId = DB::table('family_spaces')->insertGetId(['owner_user_id' => $r->user()->id, 'name' => 'Gia đình của '.$r->user()->name, 'created_at' => now(), 'updated_at' => now()]);
                    DB::table('family_members')->insert(['family_space_id' => $spaceId, 'user_id' => $r->user()->id, 'role' => 'owner', 'joined_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                }
                $child = ChildProfile::create(collect($data)->only(['name', 'date_of_birth', 'gender', 'blood_type', 'birth_weight_kg', 'birth_length_cm'])->all() + ['user_id' => $r->user()->id, 'family_space_id' => $spaceId, 'consent_revision' => 1, 'data_revision' => 1]);
                DB::table('child_data_consents')->insert(['child_profile_id' => $child->id, 'given_by' => $r->user()->id, 'purpose' => 'storage', 'policy_version' => $data['policy_version'], 'representative_confirmed' => true, 'granted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                $this->schedules->create($child->id, $data['date_of_birth']);
                if ($avatar) {
                    $path = $avatar->store('child-avatars', 'local');
                    if (! $path) {
                        throw new \RuntimeException('AVATAR_WRITE_FAILED');
                    }
                    $avatarPaths[] = $path;
                    $child->forceFill(['avatar_path' => $path, 'avatar_mime' => $avatar->getMimeType()])->save();
                }

                return $child->id;
            }, fn ($op) => $this->access->child($r->user(), $op->resource_id, true)), 3);
        } catch (\Throwable $e) {
            app(PrivateMedia::class)->cleanup($avatarPaths);
            throw $e;
        }
        $savedChild = ChildProfile::findOrFail($result['id']);
        app(PrivateMedia::class)->cleanup(array_values(array_filter($avatarPaths, fn ($path) => $path !== $savedChild->avatar_path)));
        if (! $result['replayed']) {
            try {
                GenerateMilestoneReminders::dispatch($savedChild->id);
            } catch (\Throwable $exception) {
                // The daily idempotent sync command will recover this durable profile.
                report($exception);
            }
        }

        $scheduleCount = DB::table('vaccination_schedules')->where('child_profile_id', $savedChild->id)->count();

        return response()->json([
            'success' => true,
            'data' => $savedChild,
            'replayed' => $result['replayed'],
            'meta' => [
                'vaccine_schedule_count' => $scheduleCount,
                'vaccine_catalog_ready' => $this->schedules->currentTemplates()->isNotEmpty(),
            ],
        ], 201);
    }

    public function update(Request $r, int $id)
    {
        $data = $r->validate($this->profileRules() + ['expected_data_revision' => 'required|integer|min:0']);
        $child = DB::transaction(function () use ($r, $id, $data) {
            $child = $this->access->child($r->user(), $id, true, true, true);
            abort_unless($child->data_revision === (int) $data['expected_data_revision'], 409, 'Dữ liệu đã thay đổi. Vui lòng tải lại.');
            $birth = $data['date_of_birth'];
            if ($child->date_of_birth->toDateString() !== $birth) {
                abort_if(DB::table('health_records')->where('child_profile_id', $id)->where('measured_at', '<', $birth)->exists()
                    || DB::table('vaccination_records')->where('child_profile_id', $id)->where('administered_date', '<', $birth)->exists()
                    || DB::table('memories')->where('child_profile_id', $id)->where('memory_date', '<', $birth)->exists(), 409, 'Ngày sinh mới mâu thuẫn với dữ liệu đã ghi nhận.');
                foreach (DB::table('health_records')->where('child_profile_id', $id)->get() as $record) {
                    DB::table('health_records')->where('id', $record->id)->update(['age_days' => (int) CarbonImmutable::parse($birth)->diffInDays(CarbonImmutable::parse($record->measured_at)), 'version' => $record->version + 1, 'updated_at' => now()]);
                }
                foreach (DB::table('vaccination_schedules')->where('child_profile_id', $id)->orderBy('id')->lockForUpdate()->get() as $schedule) {
                    $template = DB::table('vaccine_templates')->find($schedule->vaccine_template_id);
                    $due = $this->schedules->referenceDate($birth, $template);
                    $changes = ['due_date' => $due, 'updated_at' => now()];
                    if (! $schedule->appointment_date && $schedule->due_date !== $due) {
                        $changes += ['notified_7d_at' => null, 'notified_1d_at' => null];
                    }
                    DB::table('vaccination_schedules')->where('id', $schedule->id)->update($changes);
                }
                $this->milestones->syncForChild($id, $birth);
            }
            $child->fill(collect($data)->except('expected_data_revision')->all());
            $child->data_revision++;
            $child->save();

            return $child;
        }, 3);

        return response()->json(['success' => true, 'data' => $child]);
    }

    public function destroy(Request $r, int $id)
    {
        $data = $r->validate(['expected_data_revision' => 'required|integer|min:0', 'confirmed' => 'required|accepted']);
        DB::transaction(function () use ($r, $id, $data) {
            $child = $this->access->child($r->user(), $id, true, false, true);
            abort_unless($child->data_revision === (int) $data['expected_data_revision'], 409, 'Dữ liệu đã thay đổi. Hãy tải lại trước khi xóa hồ sơ.');
            $paths = array_filter([$child->avatar_path]);
            foreach (DB::table('memories')->where('child_profile_id', $id)->get(['id', 'source_manifest']) as $memory) {
                $paths = array_merge($paths, array_column(json_decode($memory->source_manifest, true, 512, JSON_THROW_ON_ERROR), 'path'));
                $paths = array_merge($paths, DB::table('media_files')->where('memory_id', $memory->id)->pluck('file_path')->all());
            }
            $paths = array_merge($paths, DB::table('child_profile_exports')->where('child_profile_id', $id)->whereNotNull('archive_path')->pluck('archive_path')->all());
            app(PrivateMedia::class)->cleanup($paths);
            $child->delete();
        }, 3);

        return response()->json(['success' => true, 'data' => ['deleted' => true, 'child_profile_id' => $id]]);
    }

    public function consents(Request $r, int $id)
    {
        $child = $this->access->child($r->user(), $id, true, false);

        return response()->json(['success' => true, 'data' => ['child_profile_id' => $id, 'consent_revision' => $child->consent_revision, 'data_revision' => $child->data_revision, 'policy_version' => config('babi.policy_version'), 'history' => DB::table('child_data_consents')->where('child_profile_id', $id)->orderBy('id')->get()]]);
    }

    public function grant(Request $r, int $id)
    {
        $data = $r->validate(['expected_consent_revision' => 'required|integer|min:0', 'policy_version' => ['required', Rule::in([config('babi.policy_version')])], 'representative_confirmed' => 'required|accepted', 'family_sharing_acknowledged' => 'required|accepted']);

        return $this->changeConsent($r, $id, $data, false);
    }

    public function withdraw(Request $r, int $id)
    {
        $consent = DB::table('child_data_consents')->find($id);
        abort_unless($consent, 403, 'Không có quyền truy cập.');
        $data = $r->validate(['expected_consent_revision' => 'required|integer|min:0', 'confirmed' => 'required|accepted']);

        return $this->changeConsent($r, $consent->child_profile_id, $data + ['consent_id' => $id], true);
    }

    private function changeConsent(Request $r, int $id, array $data, bool $withdraw)
    {
        DB::transaction(fn () => $this->operations->run($r->user(), $r->header('X-Operation-Key'), $withdraw ? 'consent.withdraw' : 'consent.grant', ['child_profile_id' => $id] + $data, function () use ($r, $id, $data, $withdraw) {
            $child = $this->access->child($r->user(), $id, true, false, true);
            abort_unless($child->consent_revision === (int) $data['expected_consent_revision'], 409, 'Sự đồng ý đã thay đổi. Vui lòng đọc và xác nhận lại.');
            $active = DB::table('child_data_consents')->where('child_profile_id', $id)->where('purpose', 'storage')->whereNull('withdrawn_at');
            if ($withdraw) {
                abort_unless((clone $active)->where('id', $data['consent_id'])->exists(), 409, 'Sự đồng ý không còn hiệu lực.');
            }
            $active->update(['withdrawn_at' => now(), 'updated_at' => now()]);
            if (! $withdraw) {
                DB::table('child_data_consents')->insert(['child_profile_id' => $id, 'given_by' => $r->user()->id, 'purpose' => 'storage', 'policy_version' => $data['policy_version'], 'representative_confirmed' => true, 'granted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            }
            $child->increment('consent_revision');
            $child->increment('data_revision');

            return $id;
        }, fn () => $this->access->child($r->user(), $id, true, false)), 3);

        if (! $withdraw) {
            try {
                GenerateMilestoneReminders::dispatch($id);
            } catch (\Throwable $exception) {
                // The daily idempotent sync command will recover this consent change.
                report($exception);
            }
        }

        return $this->consents($r, $id);
    }

    public function operation(Request $r, string $key)
    {
        $op = DB::table('operation_requests')->where('actor_id', $r->user()->id)->where('operation_key', strtolower($key))->first();
        abort_unless($op, 404, 'Không tìm thấy thao tác.');
        abort_if(now()->greaterThanOrEqualTo($op->expires_at), 410, 'Thao tác hết thời hạn tra cứu.');
        if ($op->resource_type === 'memory') {
            $memory = DB::table('memories')->find($op->resource_id);
            abort_unless($memory, 403, 'Không có quyền truy cập.');
            $this->access->child($r->user(), $memory->child_profile_id, true);
        } else {
            $this->access->child($r->user(), $op->resource_id, true, false);
        }

        return response()->json(['success' => true, 'data' => collect((array) $op)->only(['operation_key', 'operation_type', 'status', 'resource_id', 'expires_at'])]);
    }
}
