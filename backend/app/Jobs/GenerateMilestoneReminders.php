<?php

namespace App\Jobs;

use App\Services\MilestoneReminderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class GenerateMilestoneReminders implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public int $childProfileId)
    {
        $this->afterCommit = true;
    }

    public function handle(MilestoneReminderService $reminders): void
    {
        $child = DB::table('child_profiles')->where('id', $this->childProfileId)->first();
        if (! $child || ! DB::table('child_data_consents')->where('child_profile_id', $child->id)
            ->where('purpose', 'storage')->whereNull('withdrawn_at')->exists()) {
            return;
        }

        $reminders->syncForChild($child->id, $child->date_of_birth);
    }
}
