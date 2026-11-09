<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vaccination_schedules', function (Blueprint $table) {
            $table->timestamp('notified_3d_at')->nullable()->after('notified_7d_at');
        });
    }

    public function down(): void
    {
        Schema::table('vaccination_schedules', function (Blueprint $table) {
            $table->dropColumn('notified_3d_at');
        });
    }
};
