<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class VaccinationScheduleService
{
    public function currentVersion(): ?string
    {
        $selected = DB::table('vaccine_catalog_settings')->where('setting_key', 'expanded')->value('schedule_version');

        return $selected ?: config('babi.vaccine_catalog_version');
    }

    public function referenceDate(string $birthDate, object $template): ?string
    {
        if ($template->timing_rule === 'clinical') {
            return null;
        }
        $birth = CarbonImmutable::parse($birthDate, config('babi.calendar_timezone'));

        return ($template->age_unit === 'month'
            ? $birth->addMonthsNoOverflow($template->age_value)
            : $birth->addDays($template->age_value))->toDateString();
    }

    public function currentTemplates(): Collection
    {
        $version = $this->currentVersion();
        if (! $version) {
            return collect();
        }

        return DB::table('vaccine_templates')->where('program', 'expanded')
            ->where('schedule_version', $version)->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', today(config('babi.calendar_timezone'))))
            ->orderBy('id')->get();
    }

    public function create(int $childId, string $birthDate): bool
    {
        $templates = $this->currentTemplates();
        if ($templates->isEmpty()) {
            return false;
        }
        foreach ($templates as $template) {
            abort_if(! filter_var($template->source_url, FILTER_VALIDATE_URL)
                || ($template->timing_rule === 'age' && ($template->age_value === null || ! in_array($template->age_unit, ['day', 'month']))), 409, 'Danh mục tiêm không hợp lệ.');
            DB::table('vaccination_schedules')->insert([
                'child_profile_id' => $childId, 'vaccine_template_id' => $template->id,
                'due_date' => $this->referenceDate($birthDate, $template), 'status' => 'unrecorded',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return true;
    }
}
