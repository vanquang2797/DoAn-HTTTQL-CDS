<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $columns = [
        'child_data_consents' => ['granted_at'],
        'device_tokens' => ['last_used_at'],
        'family_invitations' => ['expires_at'],
        'family_join_requests' => ['requested_at'],
        'family_members' => ['joined_at'],
        'family_space_links' => ['expires_at'],
        'operation_requests' => ['expires_at'],
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach ($this->columns as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement("ALTER TABLE `{$table}` MODIFY `{$column}` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP");
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach ($this->columns as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement("ALTER TABLE `{$table}` MODIFY `{$column}` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
            }
        }
    }
};
