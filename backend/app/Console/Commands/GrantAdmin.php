<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GrantAdmin extends Command
{
    protected $signature = 'babi:grant-admin {email : Email of an existing verified account} {--force : Skip the interactive confirmation}';

    protected $description = 'Grant administrator access to an existing, verified, active account';

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

        if (! $user) {
            $this->error('Không tìm thấy tài khoản.');

            return self::FAILURE;
        }
        if (! $user->email_verified_at) {
            $this->error('Tài khoản phải xác thực email trước khi được cấp quyền Admin.');

            return self::FAILURE;
        }
        if ($user->status !== 'active') {
            $this->error('Không thể cấp quyền Admin cho tài khoản đã ngừng hoạt động.');

            return self::FAILURE;
        }
        if ($user->is_admin) {
            $this->info('Tài khoản đã có quyền Admin.');

            return self::SUCCESS;
        }
        if (! $this->option('force') && ! $this->confirm("Cấp quyền Admin cho {$user->email}?")) {
            $this->warn('Đã hủy, không thay đổi quyền.');

            return self::FAILURE;
        }

        $granted = DB::transaction(function () use ($user): bool {
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->first();
            if (! $locked || ! $locked->email_verified_at || $locked->status !== 'active') {
                return false;
            }
            if ($locked->is_admin) {
                return true;
            }

            $locked->is_admin = true;
            $locked->save();
            Log::notice('Administrator role granted from console', ['user_id' => $locked->id]);

            return true;
        }, 3);

        if (! $granted) {
            $this->error('Tài khoản không còn đủ điều kiện; quyền không thay đổi.');

            return self::FAILURE;
        }

        $this->info('Đã cấp quyền Admin cho tài khoản đã xác thực.');

        return self::SUCCESS;
    }
}
