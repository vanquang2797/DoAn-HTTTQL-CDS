<?php

namespace App\Http\Controllers;

use App\Services\FamilyAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MilestoneReminderController extends Controller
{
    public function __construct(private FamilyAccess $access) {}

    public function index(Request $request, int $childId)
    {
        $this->access->child($request->user(), $childId);

        return response()->json(['success' => true, 'data' => DB::table('milestone_reminders')
            ->join('milestone_templates', 'milestone_templates.id', '=', 'milestone_reminders.milestone_template_id')
            ->where('milestone_reminders.child_profile_id', $childId)->whereNull('milestone_reminders.cancelled_at')
            ->select('milestone_reminders.id', 'milestone_reminders.reminder_date', 'milestone_reminders.status',
                'milestone_reminders.notified_7d_at', 'milestone_reminders.notified_1d_at',
                'milestone_templates.name', 'milestone_templates.description', 'milestone_templates.icon_code')
            ->orderBy('milestone_reminders.reminder_date')->orderBy('milestone_reminders.id')->get()]);
    }

    public function complete(Request $request, int $id)
    {
        $item = DB::table('milestone_reminders')->where('id', $id)->first();
        abort_unless($item, 404, 'Không tìm thấy cột mốc.');

        DB::transaction(function () use ($request, $id, $item): void {
            $child = $this->access->child($request->user(), (int) $item->child_profile_id, true, true, true);
            $current = DB::table('milestone_reminders')->where('id', $id)->where('child_profile_id', $child->id)->lockForUpdate()->first();
            abort_unless($current && $current->cancelled_at === null, 404, 'Không tìm thấy cột mốc đang hoạt động.');
            if ($current->status !== 'completed') {
                DB::table('milestone_reminders')->where('id', $id)->update(['status' => 'completed', 'updated_at' => now()]);
                DB::table('milestone_notification_deliveries')->where('milestone_reminder_id', $id)
                    ->whereIn('status', ['pending', 'processing', 'failed'])
                    ->update(['status' => 'skipped', 'reason_code' => 'milestone_completed', 'claim_token' => null, 'claimed_at' => null, 'updated_at' => now()]);
            }
        }, 3);

        return response()->json(['success' => true, 'data' => DB::table('milestone_reminders')->where('id', $id)->first()]);
    }
}
