<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RevokeAdmin extends Command
{
    protected $signature = 'babi:revoke-admin {email : Email of the administrator account} {--force : Skip the interactive confirmation}';

    protected $description = 'Revoke administrator access from an account';

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

        if (! $user) {
            $this->error('Không tìm thấy tài khoản.');

            return self::FAILURE;
        }
        if (! $user->is_admin) {
            $this->info('Tài khoản không có quyền Admin.');

            return self::SUCCESS;
        }
        if (! $this->option('force') && ! $this->confirm("Thu hồi quyền Admin của {$user->email}?")) {
            $this->warn('Đã hủy, không thay đổi quyền.');

            return self::FAILURE;
        }

        $revoked = DB::transaction(function () use ($user): bool {
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->first();
            if (! $locked || ! $locked->is_admin) {
                return true;
            }

            $activeAdminIds = User::query()->where('is_admin', true)->where('status', 'active')
                ->orderBy('id')->lockForUpdate()->pluck('id');
            if ($locked->status === 'active' && $activeAdminIds->count() <= 1) {
                return false;
            }

            $locked->is_admin = false;
            $locked->save();
            Log::notice('Administrator role revoked from console', ['user_id' => $locked->id]);

            return true;
        }, 3);

        if (! $revoked) {
            $this->error('Không thể thu hồi Admin hoạt động cuối cùng.');

            return self::FAILURE;
        }

        $this->info('Đã thu hồi quyền Admin.');

        return self::SUCCESS;
    }
}
