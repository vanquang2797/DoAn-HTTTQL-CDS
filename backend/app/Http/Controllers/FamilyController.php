<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\FamilyAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class FamilyController extends Controller
{
    public function __construct(private FamilyAccess $access) {}

    public function index(Request $r)
    {
        $spaces = DB::table('family_spaces')->join('family_members', 'family_spaces.id', '=', 'family_members.family_space_id')->where('family_members.user_id', $r->user()->id)->select('family_spaces.id', 'family_spaces.name', 'family_members.role')->orderBy('family_spaces.id')->get();
        $owned = $spaces->firstWhere('role', 'owner');
        if ($owned) {
            $spaces = collect([$owned]);
        }

        return response()->json(['success' => true, 'data' => $spaces]);
    }

    public function vaccinationReminderSettings(Request $r, int $id)
    {
        $this->access->space($r->user(), $id, true);
        $settings = DB::table('vaccination_reminder_settings')->where('family_space_id', $id)->first();

        return response()->json(['success' => true, 'data' => [
            'days_before' => $settings ? json_decode($settings->days_before, true) : [7],
            'recipient_emails' => $settings ? json_decode($settings->recipient_emails, true) : [],
            'send_time' => $settings->send_time ?? '08:00',
            'start_date' => $settings->start_date ?? today('Asia/Ho_Chi_Minh')->toDateString(),
            'can_edit' => true,
        ]]);
    }

    public function vaccinationReminderDeliveries(Request $r, int $id)
    {
        $this->access->space($r->user(), $id, true);
        $r->validate(['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:50']);
        $deliveries = DB::table('vaccination_reminder_deliveries')
            ->join('vaccination_schedules', 'vaccination_schedules.id', '=', 'vaccination_reminder_deliveries.vaccination_schedule_id')
            ->join('child_profiles', 'child_profiles.id', '=', 'vaccination_schedules.child_profile_id')
            ->join('vaccine_templates', 'vaccine_templates.id', '=', 'vaccination_schedules.vaccine_template_id')
            ->where('child_profiles.family_space_id', $id)
            ->orderByDesc('vaccination_reminder_deliveries.created_at')
            ->orderByDesc('vaccination_reminder_deliveries.id')
            ->select([
                'vaccination_reminder_deliveries.id',
                'vaccination_reminder_deliveries.reminder_date',
                'vaccination_reminder_deliveries.days_before',
                'vaccination_reminder_deliveries.email',
                'vaccination_reminder_deliveries.attempt_count',
                'vaccination_reminder_deliveries.retry_after',
                'vaccination_reminder_deliveries.sent_at',
                'vaccination_reminder_deliveries.failed_at',
                'vaccination_reminder_deliveries.reason_code',
                'vaccination_reminder_deliveries.created_at',
                'vaccination_schedules.id as vaccination_schedule_id',
                'child_profiles.name as child_name',
                'vaccine_templates.name as vaccine_name',
            ])->paginate($r->integer('per_page', 20));

        $deliveries->through(function ($delivery) {
            $delivery->delivery_status = $delivery->sent_at !== null
                ? 'accepted_by_mail_transport'
                : ($delivery->failed_at !== null
                    ? 'needs_attention'
                    : ($delivery->retry_after !== null && $delivery->retry_after > now()
                        ? 'retry_wait'
                        : 'pending'));

            return $delivery;
        });

        return response()->json(['success' => true, 'data' => $deliveries])->header('Cache-Control', 'no-store, private');
    }

    public function updateVaccinationReminderSettings(Request $r, int $id)
    {
        $this->access->space($r->user(), $id, true);
        $data = $r->validate([
            'days_before' => 'required|array|min:1|max:5',
            'days_before.*' => 'required|integer|min:1|max:30|distinct',
            'recipient_emails' => 'present|array|max:10',
            'recipient_emails.*' => 'required|email:rfc|max:254|distinct',
            'send_time' => ['required', 'date_format:H:i'],
            'start_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.today('Asia/Ho_Chi_Minh')->toDateString()],
        ]);
        $days = array_values(array_unique(array_map('intval', $data['days_before'])));
        sort($days);
        $emails = array_values(array_unique(array_map(fn ($email) => mb_strtolower(trim($email)), $data['recipient_emails'])));
        $now = now();
        DB::transaction(function () use ($r, $id, $days, $emails, $data, $now) {
            // Serialize first-time settings writes for this family and avoid racing
            // against a concurrent membership/ownership change.
            $space = DB::table('family_spaces')->where('id', $id)->lockForUpdate()->first();
            abort_if(! $space, 404);
            $this->access->space($r->user(), $id, true);

            $settings = DB::table('vaccination_reminder_settings')
                ->where('family_space_id', $id)
                ->lockForUpdate()
                ->first();
            $values = [
                'days_before' => json_encode($days),
                'recipient_emails' => json_encode($emails),
                'send_time' => $data['send_time'],
                'start_date' => $data['start_date'],
                'updated_at' => $now,
            ];

            if ($settings) {
                DB::table('vaccination_reminder_settings')->where('family_space_id', $id)->update($values);
            } else {
                DB::table('vaccination_reminder_settings')->insert($values + [
                    'family_space_id' => $id,
                    'created_at' => $now,
                ]);
            }
        }, 3);

        return $this->vaccinationReminderSettings($r, $id);
    }

    public function members(Request $r, int $id)
    {
        $this->access->space($r->user(), $id);
        $members = DB::table('family_members')->join('users', 'users.id', '=', 'family_members.user_id')->where('family_space_id', $id)->select('family_members.id', 'family_members.user_id', 'family_members.role', 'users.name', 'users.avatar_url', 'users.status')->orderBy('family_members.id')->get();

        return response()->json(['success' => true, 'data' => $members]);
    }

    public function invitations(Request $r, int $id)
    {
        $this->access->space($r->user(), $id, true);
        $data = DB::table('family_invitations')->where('family_space_id', $id)->orderByDesc('id')->get(['id', 'email', 'invitation_type', 'status', 'expires_at', 'responded_at']);
        foreach ($data as $invitation) {
            if ($invitation->status === 'pending' && now()->greaterThanOrEqualTo($invitation->expires_at)) {
                $invitation->status = 'expired';
            }
        }

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function joinLinks(Request $r, int $id)
    {
        $this->access->space($r->user(), $id, true);
        $links = DB::table('family_space_links')->where('family_space_id', $id)
            ->orderByDesc('id')->get(['id', 'created_by', 'expires_at', 'revoked_at', 'created_at']);
        foreach ($links as $link) {
            $link->status = $link->revoked_at !== null ? 'revoked'
                : (now()->greaterThanOrEqualTo($link->expires_at) ? 'expired' : 'active');
        }

        return response()->json(['success' => true, 'data' => $links]);
    }

    public function createJoinLink(Request $r, int $id)
    {
        $token = Str::random(64);
        $expiresAt = now()->addDays(7);
        $linkId = DB::transaction(function () use ($r, $id, $token, $expiresAt) {
            DB::table('family_spaces')->where('id', $id)->lockForUpdate()->first();
            $this->access->space($r->user(), $id);

            return DB::table('family_space_links')->insertGetId([
                'family_space_id' => $id,
                'created_by' => $r->user()->id,
                'token_hash' => hash('sha256', $token),
                'expires_at' => $expiresAt,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }, 3);

        return response()->json(['success' => true, 'data' => [
            'id' => $linkId,
            'token' => $token,
            'expires_at' => $expiresAt->toISOString(),
            'status' => 'active',
            'role' => 'viewer',
        ]], 201);
    }

    public function revokeJoinLink(Request $r, int $id)
    {
        DB::transaction(function () use ($r, $id) {
            $link = DB::table('family_space_links')->find($id);
            abort_unless($link, 404, 'Không tìm thấy liên kết.');
            DB::table('family_spaces')->where('id', $link->family_space_id)->lockForUpdate()->first();
            $this->access->space($r->user(), $link->family_space_id, true);
            DB::table('family_space_links')->where('id', $id)->lockForUpdate()->first();
            DB::table('family_space_links')->where('id', $id)->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'updated_at' => now()]);
        }, 3);

        return response()->json(['success' => true]);
    }

    public function joinRequests(Request $r, int $id)
    {
        $this->access->space($r->user(), $id, true);
        $requests = DB::table('family_join_requests')->join('users', 'users.id', '=', 'family_join_requests.user_id')
            ->where('family_join_requests.family_space_id', $id)->orderByDesc('family_join_requests.requested_at')
            ->get(['family_join_requests.id', 'family_join_requests.user_id', 'family_join_requests.status', 'family_join_requests.requested_at', 'users.name', 'users.email']);

        return response()->json(['success' => true, 'data' => $requests]);
    }

    public function previewJoin(Request $r)
    {
        $data = $this->validateJoinToken($r);
        $link = DB::table('family_space_links')->where('token_hash', hash('sha256', $data['token']))->first();
        abort_unless($link, 403, 'Liên kết gia đình không hợp lệ.');
        abort_if($link->revoked_at !== null, 410, 'Liên kết gia đình đã bị thu hồi.');
        abort_if(now()->greaterThanOrEqualTo($link->expires_at), 410, 'Liên kết gia đình đã hết hạn.');
        $creator = User::find($link->created_by);
        abort_unless($creator, 403, 'Người chia sẻ không còn quyền trong gia đình.');
        $this->access->space($creator, $link->family_space_id);
        $member = DB::table('family_members')->where('family_space_id', $link->family_space_id)->where('user_id', $r->user()->id)->exists();
        $request = DB::table('family_join_requests')->where('family_space_id', $link->family_space_id)->where('user_id', $r->user()->id)->first();

        return response()->json(['success' => true, 'data' => [
            'id' => $request?->id,
            'status' => $member ? 'approved' : ($request?->status === 'pending' ? 'pending' : 'available'),
            'already_member' => $member,
            'family_space_id' => $member ? $link->family_space_id : null,
            'email_verified' => $r->user()->hasVerifiedEmail(),
            'owner_conflict' => ! $member && DB::table('family_members')->where('user_id', $r->user()->id)->where('role', 'owner')->exists(),
        ]]);
    }

    public function requestJoin(Request $r)
    {
        $data = $this->validateJoinToken($r);
        $result = DB::transaction(function () use ($r, $data) {
            $user = User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless($user->hasVerifiedEmail(), 403, 'Hãy xác thực email trước khi gửi yêu cầu tham gia.');
            $link = DB::table('family_space_links')->where('token_hash', hash('sha256', $data['token']))->first();
            abort_unless($link, 403, 'Liên kết gia đình không hợp lệ.');
            DB::table('family_spaces')->where('id', $link->family_space_id)->lockForUpdate()->first();
            $link = DB::table('family_space_links')->where('id', $link->id)->lockForUpdate()->first();
            abort_if($link->revoked_at !== null, 410, 'Liên kết gia đình đã bị thu hồi.');
            abort_if(now()->greaterThanOrEqualTo($link->expires_at), 410, 'Liên kết gia đình đã hết hạn.');
            $creator = User::find($link->created_by);
            abort_unless($creator, 403, 'Người chia sẻ không còn quyền trong gia đình.');
            $this->access->space($creator, $link->family_space_id);
            if (DB::table('family_members')->where('family_space_id', $link->family_space_id)->where('user_id', $user->id)->exists()) {
                $existing = DB::table('family_join_requests')->where('family_space_id', $link->family_space_id)->where('user_id', $user->id)->first();

                return ['id' => $existing?->id, 'status' => 'approved', 'already_member' => true, 'family_space_id' => $link->family_space_id];
            }
            abort_if(DB::table('family_members')->where('user_id', $user->id)->where('role', 'owner')->exists(), 403, 'Owner chỉ được truy cập Family Space mình quản lý.');
            $existing = DB::table('family_join_requests')->where('family_space_id', $link->family_space_id)->where('user_id', $user->id)->lockForUpdate()->first();
            if ($existing && $existing->status === 'pending') {
                return ['id' => $existing->id, 'status' => 'pending'];
            }
            $values = [
                'family_space_link_id' => $link->id,
                'status' => 'pending',
                'requested_at' => now(),
                'reviewed_by' => null,
                'responded_at' => null,
                'updated_at' => now(),
            ];
            if ($existing) {
                DB::table('family_join_requests')->where('id', $existing->id)->update($values);
                $requestId = $existing->id;
            } else {
                $requestId = DB::table('family_join_requests')->insertGetId($values + [
                    'family_space_id' => $link->family_space_id,
                    'user_id' => $user->id,
                    'created_at' => now(),
                ]);
            }

            return ['id' => $requestId, 'status' => 'pending'];
        }, 3);

        return response()->json(['success' => true, 'data' => $result], $result['status'] === 'pending' ? 202 : 200);
    }

    public function joinRequestStatus(Request $r, int $id)
    {
        $request = DB::table('family_join_requests')->where('id', $id)->first();
        abort_unless($request && (int) $request->user_id === (int) $r->user()->id, 404, 'Không tìm thấy yêu cầu.');

        return response()->json(['success' => true, 'data' => ['id' => $request->id, 'status' => $request->status, 'responded_at' => $request->responded_at]]);
    }

    public function approveJoinRequest(Request $r, int $id)
    {
        $result = DB::transaction(function () use ($r, $id) {
            $request = DB::table('family_join_requests')->find($id);
            abort_unless($request, 404, 'Không tìm thấy yêu cầu.');
            DB::table('family_spaces')->where('id', $request->family_space_id)->lockForUpdate()->first();
            $this->access->space($r->user(), $request->family_space_id, true);
            $request = DB::table('family_join_requests')->where('id', $id)->lockForUpdate()->first();
            abort_unless($request->status === 'pending', 409, 'Yêu cầu đã được xử lý.');
            $user = User::whereKey($request->user_id)->lockForUpdate()->first();
            abort_unless($user && $user->status === 'active' && $user->hasVerifiedEmail(), 403, 'Tài khoản yêu cầu không còn đủ điều kiện.');
            abort_if(DB::table('family_members')->where('user_id', $user->id)->where('role', 'owner')->exists(), 409, 'Owner chỉ được truy cập Family Space mình quản lý.');
            abort_if(DB::table('family_members')->where('family_space_id', $request->family_space_id)->where('user_id', $user->id)->exists(), 409, 'Tài khoản đã là thành viên.');
            $memberId = DB::table('family_members')->insertGetId([
                'family_space_id' => $request->family_space_id,
                'user_id' => $user->id,
                'role' => 'viewer',
                'joined_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('family_join_requests')->where('id', $id)->update([
                'status' => 'approved', 'reviewed_by' => $r->user()->id,
                'responded_at' => now(), 'updated_at' => now(),
            ]);

            return ['id' => $id, 'member_id' => $memberId, 'status' => 'approved', 'role' => 'viewer'];
        }, 3);

        return response()->json(['success' => true, 'data' => $result]);
    }

    public function declineJoinRequest(Request $r, int $id)
    {
        DB::transaction(function () use ($r, $id) {
            $request = DB::table('family_join_requests')->find($id);
            abort_unless($request, 404, 'Không tìm thấy yêu cầu.');
            DB::table('family_spaces')->where('id', $request->family_space_id)->lockForUpdate()->first();
            $this->access->space($r->user(), $request->family_space_id, true);
            $request = DB::table('family_join_requests')->where('id', $id)->lockForUpdate()->first();
            abort_unless($request->status === 'pending', 409, 'Yêu cầu đã được xử lý.');
            DB::table('family_join_requests')->where('id', $id)->update([
                'status' => 'declined', 'reviewed_by' => $r->user()->id,
                'responded_at' => now(), 'updated_at' => now(),
            ]);
        }, 3);

        return response()->json(['success' => true]);
    }

    public function invite(Request $r, int $id)
    {
        abort_unless($r->user()->hasVerifiedEmail(), 403, 'Xác thực email trước khi gửi lời mời.');
        $r->merge(['email' => mb_strtolower(trim((string) $r->input('email')))]);
        $data = $r->validate(['email' => 'required|email|max:255', 'invitation_type' => 'required|in:family_viewer,co_manager', 'confirmed' => 'required|accepted']);
        $token = Str::random(64);
        $invitationId = DB::transaction(function () use ($r, $id, $data, $token) {
            DB::table('family_spaces')->where('id', $id)->lockForUpdate()->first();
            $this->access->space($r->user(), $id, true);
            abort_unless($r->user()->fresh()->hasVerifiedEmail(), 403, 'Xác thực email trước khi gửi lời mời.');
            abort_if($data['email'] === $r->user()->email, 422, 'Không thể tự mời mình.');
            abort_if(DB::table('family_members')->join('users', 'users.id', '=', 'family_members.user_id')->where('family_space_id', $id)->where('users.email', $data['email'])->exists(), 409, 'Tài khoản đã là thành viên.');
            abort_if(DB::table('family_invitations')->where('family_space_id', $id)->where('email', $data['email'])->where('status', 'pending')->where('expires_at', '>', now())->exists(), 409, 'Đã có lời mời còn hạn.');

            return DB::table('family_invitations')->insertGetId(['family_space_id' => $id, 'invited_by' => $r->user()->id, 'email' => $data['email'], 'invitation_type' => $data['invitation_type'], 'token_hash' => hash('sha256', $token), 'status' => 'pending', 'expires_at' => now()->addDays(7), 'created_at' => now(), 'updated_at' => now()]);
        }, 3);
        $delivery = $this->send($data['email'], $token);

        return response()->json(['success' => true, 'data' => ['id' => $invitationId, 'status' => 'pending', 'delivery' => $delivery]], 201);
    }

    private function send(string $email, string $token): string
    {
        try {
            $url = rtrim((string) config('babi.frontend_url'), '/').'/'.'?familyInvite='.urlencode($token);
            Mail::raw("Bạn được mời tham gia gia đình trên Babi. Mở liên kết để xem người mời và quyền được đề nghị, rồi tự chấp nhận hoặc từ chối: {$url}\nĐăng nhập bằng đúng email này và xác thực email trước khi chấp nhận. Lời mời có hạn 7 ngày; chưa có quyền truy cập cho đến khi bạn chấp nhận.\nMã dự phòng: {$token}", fn ($m) => $m->to($email)->subject('Lời mời gia đình Babi'));

            return config('mail.default') === 'log' ? 'logged_locally' : 'submitted';
        } catch (\Throwable $e) {
            // Do not put the invitation token or email body in operational logs.
            return 'failed';
        }
    }

    public function accept(Request $r)
    {
        return $this->respond($r, true);
    }

    public function decline(Request $r)
    {
        return $this->respond($r, false);
    }

    public function previewInvitation(Request $r)
    {
        $data = $this->validateInvitationToken($r);
        $invitation = DB::table('family_invitations')->where('token_hash', hash('sha256', $data['token']))->first();
        abort_unless($invitation, 404, 'Không tìm thấy lời mời.');
        $space = DB::table('family_spaces')->where('id', $invitation->family_space_id)->first();
        $inviter = User::find($invitation->invited_by);
        abort_unless($space && $inviter, 404, 'Không tìm thấy lời mời.');
        $inviterIsOwner = $inviter->status === 'active' && DB::table('family_members')
            ->where('family_space_id', $invitation->family_space_id)
            ->where('user_id', $inviter->id)->where('role', 'owner')->exists();
        $alreadyMember = DB::table('family_members')->where('family_space_id', $invitation->family_space_id)
            ->where('user_id', $r->user()->id)->exists();
        $hasOwnedSpace = DB::table('family_members')
            ->where('user_id', $r->user()->id)->where('role', 'owner')->exists();
        $hasOtherFamilyMembership = DB::table('family_members')
            ->where('user_id', $r->user()->id)
            ->where('family_space_id', '!=', $invitation->family_space_id)
            ->exists();
        // Owners are tied to the single space they manage. A viewer can join
        // several families, but cannot become an owner while belonging to others.
        $ownerConflict = $invitation->invitation_type === 'co_manager'
            ? $hasOtherFamilyMembership
            : $hasOwnedSpace;
        $expired = now()->greaterThanOrEqualTo($invitation->expires_at);
        $status = $expired && $invitation->status === 'pending' ? 'expired' : $invitation->status;
        $emailMatches = mb_strtolower($r->user()->email) === mb_strtolower($invitation->email);

        return response()->json(['success' => true, 'data' => [
            'id' => $invitation->id,
            'family_name' => $space->name,
            'inviter_name' => $inviter->name,
            'invited_email' => $invitation->email,
            'email_matches' => $emailMatches,
            'email_verified' => $r->user()->hasVerifiedEmail(),
            'inviter_active' => $inviterIsOwner,
            'already_member' => $alreadyMember,
            'owner_conflict' => $ownerConflict,
            'invitation_type' => $invitation->invitation_type,
            'role' => $invitation->invitation_type === 'co_manager' ? 'owner' : 'viewer',
            'status' => $status,
            'expires_at' => $invitation->expires_at,
            'can_respond' => $status === 'pending' && ! $expired && $emailMatches && $r->user()->hasVerifiedEmail() && $inviterIsOwner && ! $alreadyMember && ! $ownerConflict,
        ]]);
    }

    private function respond(Request $r, bool $accept)
    {
        $data = $this->validateInvitationToken($r);
        $result = DB::transaction(function () use ($r, $data, $accept) {
            // Lock the account so child creation, approvals, and invitations serialize.
            $user = User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
            $invitation = DB::table('family_invitations')->where('token_hash', hash('sha256', $data['token']))->first();
            abort_unless($invitation, 403, 'Lời mời không hợp lệ.');
            DB::table('family_spaces')->where('id', $invitation->family_space_id)->lockForUpdate()->first();
            $invitation = DB::table('family_invitations')->where('id', $invitation->id)->lockForUpdate()->first();
            abort_unless($user->hasVerifiedEmail() && mb_strtolower($user->email) === mb_strtolower($invitation->email), 403, 'Cần đúng email đã xác thực.');
            abort_if(now()->greaterThanOrEqualTo($invitation->expires_at), 410, 'Lời mời đã hết hạn.');
            abort_unless($invitation->status === 'pending', 409, 'Lời mời đã kết thúc.');
            $inviter = User::find($invitation->invited_by);
            abort_unless($inviter && $inviter->status === 'active', 403, 'Người mời không còn đủ điều kiện.');
            $this->access->space($inviter, $invitation->family_space_id, true);
            $role = $invitation->invitation_type === 'co_manager' ? 'owner' : 'viewer';
            $memberId = null;
            if ($accept) {
                abort_if(DB::table('family_members')->where('family_space_id', $invitation->family_space_id)->where('user_id', $user->id)->exists(), 409, 'Đã là thành viên.');
                $hasOwnedSpace = DB::table('family_members')->where('user_id', $user->id)->where('role', 'owner')->exists();
                $hasOtherFamilyMembership = DB::table('family_members')
                    ->where('user_id', $user->id)
                    ->where('family_space_id', '!=', $invitation->family_space_id)
                    ->exists();
                abort_if(
                    ($role === 'owner' && $hasOtherFamilyMembership)
                        || ($role === 'viewer' && $hasOwnedSpace),
                    409,
                    $role === 'owner'
                        ? 'Chỉ tài khoản chưa thuộc Family Space nào mới có thể trở thành Owner.'
                        : 'Owner chỉ được truy cập Family Space mà mình quản lý.'
                );
                $memberId = DB::table('family_members')->insertGetId(['family_space_id' => $invitation->family_space_id, 'user_id' => $user->id, 'role' => $role, 'joined_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                // A pending request made through a shared family link is
                // superseded when this account joins through a direct invite.
                // Keep the Owner's review queue consistent with membership.
                DB::table('family_join_requests')->where('family_space_id', $invitation->family_space_id)
                    ->where('user_id', $user->id)->where('status', 'pending')
                    ->update([
                        'status' => 'approved',
                        'reviewed_by' => $invitation->invited_by,
                        'responded_at' => now(),
                        'updated_at' => now(),
                    ]);
            }
            DB::table('family_invitations')->where('id', $invitation->id)->update(['status' => $accept ? 'accepted' : 'declined', 'responded_at' => now(), 'updated_at' => now()]);

            return ['family_space_id' => $invitation->family_space_id, 'member_id' => $memberId, 'role' => $accept ? $role : null, 'status' => $accept ? 'accepted' : 'declined'];
        }, 3);

        return response()->json(['success' => true, 'data' => $result]);
    }

    private function validateInvitationToken(Request $r): array
    {
        return $r->validate(
            ['token' => ['required', 'string', 'size:64']],
            [
                'token.required' => 'Thiếu mã lời mời. Hãy mở lại liên kết đầy đủ trong email.',
                'token.string' => 'Mã lời mời không hợp lệ. Hãy mở lại liên kết đầy đủ trong email.',
                'token.size' => 'Mã lời mời không hợp lệ hoặc đã bị cắt bớt. Hãy mở lại toàn bộ liên kết trong email.',
            ],
        );
    }

    private function validateJoinToken(Request $r): array
    {
        return $r->validate(
            ['token' => ['required', 'string', 'regex:/^[A-Za-z0-9]{64}$/']],
            [
                'token.required' => 'Thiếu mã liên kết gia đình. Hãy mở lại liên kết đầy đủ.',
                'token.string' => 'Mã liên kết gia đình không hợp lệ. Hãy mở lại liên kết đầy đủ.',
                'token.regex' => 'Mã liên kết gia đình không hợp lệ hoặc đã bị cắt bớt. Hãy mở lại toàn bộ liên kết đã được chia sẻ.',
            ],
        );
    }

    public function revoke(Request $r, int $id)
    {
        DB::transaction(function () use ($r, $id) {
            $inv = DB::table('family_invitations')->find($id);
            abort_unless($inv, 403, 'Không có quyền truy cập.');
            DB::table('family_spaces')->where('id', $inv->family_space_id)->lockForUpdate()->first();
            $this->access->space($r->user(), $inv->family_space_id, true);
            $inv = DB::table('family_invitations')->where('id', $id)->lockForUpdate()->first();
            abort_unless($inv->status === 'pending', 409, 'Lời mời đã kết thúc.');
            DB::table('family_invitations')->where('id', $id)->update(['status' => 'revoked', 'responded_at' => now(), 'updated_at' => now()]);
        });

        return response()->json(['success' => true]);
    }

    public function resend(Request $r, int $id)
    {
        $token = Str::random(64);
        $invitation = DB::transaction(function () use ($r, $id) {
            $inv = DB::table('family_invitations')->find($id);
            abort_unless($inv, 403, 'Không có quyền truy cập.');
            DB::table('family_spaces')->where('id', $inv->family_space_id)->lockForUpdate()->first();
            $this->access->space($r->user(), $inv->family_space_id, true);
            abort_unless($r->user()->fresh()->hasVerifiedEmail(), 403, 'Xác thực email trước khi gửi lại lời mời.');
            $inv = DB::table('family_invitations')->where('id', $id)->lockForUpdate()->first();
            abort_unless($inv->status === 'pending', 409, 'Lời mời đã kết thúc.');
            abort_if(now()->greaterThanOrEqualTo($inv->expires_at), 410, 'Lời mời đã hết hạn.');

            return $inv;
        }, 3);
        $delivery = $this->send($invitation->email, $token);
        if ($delivery !== 'failed') {
            DB::transaction(function () use ($id, $invitation, $token) {
                DB::table('family_spaces')->where('id', $invitation->family_space_id)->lockForUpdate()->first();
                $current = DB::table('family_invitations')->where('id', $id)->lockForUpdate()->first();
                abort_unless($current && $current->status === 'pending', 409, 'Lời mời đã kết thúc trong lúc gửi lại.');
                abort_if(now()->greaterThanOrEqualTo($current->expires_at), 410, 'Lời mời đã hết hạn.');
                // Replace the secret only after delivery is accepted; never extend
                // the original seven-day deadline or invalidate a working email on failure.
                DB::table('family_invitations')->where('id', $id)->update([
                    'token_hash' => hash('sha256', $token),
                    'updated_at' => now(),
                ]);
            }, 3);
        }

        return response()->json(['success' => true, 'data' => ['id' => $id, 'status' => 'pending', 'delivery' => $delivery]]);
    }

    public function demote(Request $r, int $id)
    {
        $r->validate(['action' => 'required|in:demote']);

        return $this->changeMember($r, $id, false);
    }

    public function remove(Request $r, int $id)
    {
        return $this->changeMember($r, $id, true);
    }

    private function changeMember(Request $r, int $id, bool $remove)
    {
        $r->validate(['confirmed' => 'required|accepted']);
        DB::transaction(function () use ($r, $id, $remove) {
            $member = DB::table('family_members')->find($id);
            abort_unless($member, 403, 'Không có quyền truy cập.');
            DB::table('family_spaces')->where('id', $member->family_space_id)->lockForUpdate()->first();
            $this->access->space($r->user(), $member->family_space_id, true);
            $member = DB::table('family_members')->where('id', $id)->lockForUpdate()->first();
            abort_unless($member, 409, 'Thành viên đã thay đổi.');
            if ($member->role === 'owner') {
                $others = DB::table('family_members')->join('users', 'users.id', '=', 'family_members.user_id')->where('family_space_id', $member->family_space_id)->where('role', 'owner')->where('users.status', 'active')->where('family_members.id', '!=', $id)->exists();
                abort_unless($others, 409, 'Gia đình phải còn ít nhất một Owner hoạt động.');
            }
            if ($remove) {
                DB::table('family_members')->where('id', $id)->delete();
            } else {
                abort_unless($member->role === 'owner', 409, 'Thành viên không có quyền quản lý.');
                DB::table('family_members')->where('id', $id)->update(['role' => 'viewer', 'updated_at' => now()]);
            }
        }, 3);

        return response()->json(['success' => true]);
    }
}
