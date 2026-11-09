<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vaccination_reminder_settings', function (Blueprint $table) {
            $table->date('start_date')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('vaccination_reminder_settings', function (Blueprint $table) {
            $table->dropColumn('start_date');
        });
    }
};
