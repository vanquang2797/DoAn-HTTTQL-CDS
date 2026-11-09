<?php

namespace App\Console\Commands;

use App\Services\VaccinationScheduleService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PopulateMissingVaccinationSchedules extends Command
{
    protected $signature = 'babi:populate-missing-vaccination-schedules';

    protected $description = 'Create schedules for consenting child profiles that have no schedule, using the selected reviewed catalog';

    public function handle(VaccinationScheduleService $schedules): int
    {
        if ($schedules->currentTemplates()->isEmpty()) {
            $this->error('Chưa chọn danh mục tiêm mở rộng đang có hiệu lực; không có hồ sơ nào được thay đổi.');

            return self::FAILURE;
        }

        $created = 0;
        $skipped = 0;
        DB::table('child_profiles')->whereNotExists(function ($query) {
            $query->selectRaw('1')->from('vaccination_schedules')
                ->whereColumn('vaccination_schedules.child_profile_id', 'child_profiles.id');
        })->whereExists(function ($query) {
            $query->selectRaw('1')->from('child_data_consents')
                ->whereColumn('child_data_consents.child_profile_id', 'child_profiles.id')
                ->where('child_data_consents.purpose', 'storage')->whereNull('child_data_consents.withdrawn_at');
        })->orderBy('id')->chunkById(100, function ($children) use ($schedules, &$created, &$skipped) {
            foreach ($children as $candidate) {
                $wasCreated = DB::transaction(function () use ($candidate, $schedules) {
                    $child = DB::table('child_profiles')->where('id', $candidate->id)->lockForUpdate()->first();
                    if (! $child
                        || DB::table('vaccination_schedules')->where('child_profile_id', $child->id)->exists()
                        || ! DB::table('child_data_consents')->where('child_profile_id', $child->id)->where('purpose', 'storage')->whereNull('withdrawn_at')->exists()) {
                        return false;
                    }

                    if (! $schedules->create($child->id, $child->date_of_birth)) {
                        return false;
                    }

                    DB::table('child_profiles')->where('id', $child->id)->increment('data_revision', 1, ['updated_at' => now()]);

                    return true;
                }, 3);

                $wasCreated ? $created++ : $skipped++;
            }
        });

        $this->info("Created schedules for {$created} child profile(s); skipped {$skipped} changed or ineligible profile(s).");

        return self::SUCCESS;
    }
}
