<?php

namespace App\Console\Commands;

use App\Services\MilestoneReminderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncMilestoneReminders extends Command
{
    protected $signature = 'babi:sync-milestone-reminders';

    protected $description = 'Idempotently synchronize birthday milestones for children with active storage consent';

    public function handle(MilestoneReminderService $reminders): int
    {
        $processed = 0;
        DB::table('child_profiles')->whereExists(fn ($query) => $query->selectRaw('1')->from('child_data_consents')
            ->whereColumn('child_data_consents.child_profile_id', 'child_profiles.id')
            ->where('child_data_consents.purpose', 'storage')->whereNull('child_data_consents.withdrawn_at'))
            ->orderBy('id')->chunkById(100, function ($children) use ($reminders, &$processed): void {
                foreach ($children as $child) {
                    $reminders->syncForChild((int) $child->id, $child->date_of_birth);
                    $processed++;
                }
            });

        $this->info("Synchronized milestone reminders for {$processed} child profile(s).");

        return self::SUCCESS;
    }
}
