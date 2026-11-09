<?php

namespace App\Services;

use App\Models\ChildProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class FamilyAccess
{
    public function space(User $user, int $spaceId, bool $owner = false): object
    {
        abort_unless($user->status === 'active', 401, 'Phiên không hợp lệ.');
        $member = DB::table('family_members')->where('family_space_id', $spaceId)->where('user_id', $user->id)->first();
        abort_unless($member && (! $owner || $member->role === 'owner'), 403, 'Không có quyền truy cập.');
        $ownedSpaceId = DB::table('family_members')->where('user_id', $user->id)
            ->where('role', 'owner')->value('family_space_id');
        abort_unless($ownedSpaceId === null || (int) $ownedSpaceId === $spaceId, 403, 'Owner chỉ được truy cập Family Space mình quản lý.');

        return $member;
    }

    // Mutation callers must lock the Space before the child to serialize permission changes.
    public function child(User $user, int $id, bool $owner = false, bool $storage = true, bool $lock = false): ChildProfile
    {
        $child = ChildProfile::find($id);
        abort_unless($child, 403, 'Không có quyền truy cập.');
        if ($lock) {
            DB::table('family_spaces')->where('id', $child->family_space_id)->lockForUpdate()->first();
            $child = ChildProfile::whereKey($id)->lockForUpdate()->first();
            abort_unless($child, 403, 'Không có quyền truy cập.');
        }
        $this->space($user, $child->family_space_id, $owner);
        if ($storage) {
            abort_unless(DB::table('child_data_consents')->where('child_profile_id', $id)->where('purpose', 'storage')->whereNull('withdrawn_at')->exists(), 403, 'Đồng ý lưu dữ liệu đã được rút.');
        }

        return $child;
    }
}
