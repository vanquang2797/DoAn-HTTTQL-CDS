<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vaccination_reminder_deliveries', function (Blueprint $table) {
            $table->timestamp('retry_after')->nullable()->after('claimed_at');
        });
    }

    public function down(): void
    {
        Schema::table('vaccination_reminder_deliveries', function (Blueprint $table) {
            $table->dropColumn('retry_after');
        });
    }
};
