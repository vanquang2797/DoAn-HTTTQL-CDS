<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Notifications\VerifyEmailWithFamilyLink;
use App\Services\DeviceTokenRegistry;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function register(Request $r)
    {
        $r->merge(['email' => mb_strtolower(trim((string) $r->input('email')))]);
        $data = $r->validate(['name' => 'required|string|max:100', 'email' => 'required|email|max:255|unique:users', 'password' => 'required|string|min:8|max:72|confirmed']);
        $user = User::create($data);

        return response()->json(['success' => true, 'data' => $this->session($user)], 201);
    }

    public function login(Request $r)
    {
        $r->merge(['email' => mb_strtolower(trim((string) $r->input('email')))]);
        $data = $r->validate(['email' => 'required|email', 'password' => 'required|string']);
        $user = User::where('email', $data['email'])->first();
        abort_unless($user && Hash::check($data['password'], $user->password) && $user->status === 'active', 401, 'Thông tin đăng nhập không chính xác.');

        return response()->json(['success' => true, 'data' => $this->session($user)]);
    }

    private function session(User $user): array
    {
        $expires = now()->addDays(7);

        return ['user' => $user, 'token' => $user->createToken('mobile', ['*'], $expires)->plainTextToken, 'token_type' => 'Bearer', 'expires_at' => $expires->toISOString()];
    }

    public function me(Request $r)
    {
        return response()->json(['success' => true, 'data' => $r->user()]);
    }

    public function update(Request $r)
    {
        $data = $r->validate(['name' => 'sometimes|required|string|max:100', 'avatar_url' => 'sometimes|nullable|url|max:500', 'email' => 'prohibited', 'is_admin' => 'prohibited', 'status' => 'prohibited']);
        $r->user()->update($data);

        return $this->me($r);
    }

    public function logout(Request $r, DeviceTokenRegistry $devices)
    {
        $data = $r->validate(['installation_id' => 'sometimes|uuid']);
        if (! empty($data['installation_id'])) {
            DB::transaction(fn () => $devices->remove($r->user()->id, $data['installation_id']), 3);
        }
        $r->user()->currentAccessToken()->delete();

        return response()->json(['success' => true, 'message' => 'Đã đăng xuất.']);
    }

    public function password(Request $r, DeviceTokenRegistry $devices)
    {
        $data = $r->validate(['current_password' => 'required|string', 'password' => 'required|string|min:8|max:72|confirmed']);
        DB::transaction(function () use ($r, $data, $devices) {
            $user = User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless(Hash::check($data['current_password'], $user->password), 422, 'Mật khẩu hiện tại không đúng.');
            $user->update(['password' => $data['password']]);
            $user->tokens()->delete();
            $devices->remove($user->id);
        });

        return response()->json(['success' => true, 'message' => 'Đã đổi mật khẩu. Vui lòng đăng nhập lại.']);
    }

    public function sendVerification(Request $r)
    {
        $data = $r->validate(['family_join_token' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Za-z0-9]{64}$/']]);
        if ($r->user()->hasVerifiedEmail()) {
            return response()->json([
                'success' => true,
                'email_verified' => true,
                'message' => 'Email đã được xác thực. Không cần gửi lại thư.',
            ]);
        }

        $r->user()->notify(new VerifyEmailWithFamilyLink($data['family_join_token'] ?? null));

        return response()->json([
            'success' => true,
            'email_verified' => false,
            'message' => 'Đã chuyển yêu cầu xác thực tới dịch vụ thư.',
        ]);
    }

    public function sendPasswordReset(Request $r)
    {
        $data = $r->validate(['email' => 'required|email|max:255']);
        $status = Password::sendResetLink(['email' => mb_strtolower(trim($data['email']))]);

        // Avoid disclosing whether an account exists.
        return response()->json(['success' => true, 'message' => 'Nếu email đã đăng ký, hướng dẫn đặt lại mật khẩu sẽ được gửi tới hộp thư.']);
    }

    public function resetPassword(Request $r, DeviceTokenRegistry $devices)
    {
        $data = $r->validate([
            'email' => 'required|email|max:255', 'token' => 'required|string',
            'password' => 'required|string|min:8|max:72|confirmed',
            'password_confirmation' => 'required|string|same:password',
        ]);
        $status = Password::reset([
            'email' => mb_strtolower(trim($data['email'])), 'token' => $data['token'],
            'password' => $data['password'], 'password_confirmation' => $data['password_confirmation'],
        ], function (User $user, string $password) use ($devices) {
            DB::transaction(function () use ($user, $password, $devices) {
                $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                $locked->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
                $locked->tokens()->delete();
                $devices->remove($locked->id);
            }, 3);
            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json(['success' => false, 'message' => __($status)], 422);
        }

        return response()->json(['success' => true, 'message' => __($status)]);
    }

    public function verify(Request $r)
    {
        abort_unless((int) $r->query('id') === $r->user()->id && hash_equals(sha1($r->user()->email), (string) $r->query('hash')), 403, 'Bạn đang đăng nhập tài khoản khác với email cần xác thực. Hãy đổi tài khoản rồi mở lại thư.');
        abort_unless($r->hasValidSignature(), 403, 'Liên kết xác thực không hợp lệ hoặc hết hạn. Hãy gửi lại thư xác thực cho tài khoản này.');
        $r->user()->markEmailAsVerified();

        return response()->json(['success' => true, 'message' => 'Đã xác thực email.']);
    }
}
