<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The old UI defaulted to both 7 and 3 days. Keep other custom choices.
        foreach (DB::table('vaccination_reminder_settings')->get(['family_space_id', 'days_before']) as $settings) {
            $days = json_decode($settings->days_before, true);
            if (is_array($days)) {
                $normalized = array_values(array_unique(array_map('intval', $days)));
                sort($normalized);
                if ($normalized === [3, 7]) {
                    DB::table('vaccination_reminder_settings')->where('family_space_id', $settings->family_space_id)
                        ->update(['days_before' => json_encode([7])]);
                }
            }
        }

        Schema::table('vaccination_reminder_settings', function (Blueprint $table) {
            $columns = array_values(array_filter(['frequency', 'weekday'], fn ($column) => Schema::hasColumn('vaccination_reminder_settings', $column)));
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }

    public function down(): void
    {
        Schema::table('vaccination_reminder_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('vaccination_reminder_settings', 'frequency')) {
                $table->string('frequency', 10)->default('daily');
            }
            if (! Schema::hasColumn('vaccination_reminder_settings', 'weekday')) {
                $table->unsignedTinyInteger('weekday')->default(1);
            }
        });
    }
};
