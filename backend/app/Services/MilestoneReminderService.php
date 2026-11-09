<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class MilestoneReminderService
{
    public function syncForChild(int $childId, string $birthDate): void
    {
        $today = CarbonImmutable::today(config('babi.calendar_timezone'));
        $expected = [];

        foreach (DB::table('milestone_templates')->orderBy('id')->get() as $template) {
            $birth = CarbonImmutable::parse($birthDate, config('babi.calendar_timezone'));
            $ages = [(int) $template->age_months];
            if ($template->is_recurring) {
                for ($age = (int) $template->age_months + 12; $age <= 60; $age += 12) {
                    $ages[] = $age;
                }
            }
            foreach ($ages as $age) {
                if ($age < 1 || $age > 60) {
                    continue;
                }
                $date = $birth->addMonthsNoOverflow($age)->toDateString();
                if ($date < $today->toDateString()) {
                    continue;
                }
                $expected[$template->id.':'.$date] = [
                    'child_profile_id' => $childId,
                    'milestone_template_id' => $template->id,
                    'reminder_date' => $date,
                ];
            }
        }

        DB::transaction(function () use ($childId, $expected): void {
            DB::table('child_profiles')->where('id', $childId)->lockForUpdate()->first();
            $existing = DB::table('milestone_reminders')->where('child_profile_id', $childId)->lockForUpdate()->get();
            $existingKeys = [];
            foreach ($existing as $reminder) {
                $key = $reminder->milestone_template_id.':'.$reminder->reminder_date;
                $existingKeys[$key] = true;
                if ($reminder->status === 'completed') {
                    continue;
                }
                if (isset($expected[$key])) {
                    if ($reminder->cancelled_at !== null) {
                        DB::table('milestone_reminders')->where('id', $reminder->id)->update([
                            'cancelled_at' => null,
                            'status' => 'pending',
                            'updated_at' => now(),
                        ]);
                        DB::table('milestone_notification_deliveries')->where('milestone_reminder_id', $reminder->id)
                            ->where('status', 'skipped')->where('reason_code', 'milestone_date_changed')
                            ->update(['status' => 'pending', 'reason_code' => null, 'attempt_count' => 0, 'retry_after' => null, 'updated_at' => now()]);
                    }

                    continue;
                }
                if ($reminder->cancelled_at !== null) {
                    continue;
                }
                DB::table('milestone_reminders')->where('id', $reminder->id)->whereNull('cancelled_at')
                    ->update(['cancelled_at' => now(), 'updated_at' => now()]);
                DB::table('milestone_notification_deliveries')->where('milestone_reminder_id', $reminder->id)
                    ->whereIn('status', ['pending', 'processing', 'failed'])
                    ->update(['status' => 'skipped', 'reason_code' => 'milestone_date_changed', 'claim_token' => null, 'claimed_at' => null, 'updated_at' => now()]);
            }

            foreach ($expected as $key => $values) {
                if (isset($existingKeys[$key])) {
                    continue;
                }
                DB::table('milestone_reminders')->insertOrIgnore($values + [
                    'status' => 'pending',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }, 3);
    }
}
