<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vaccination_reminder_settings', function (Blueprint $table) {
            $table->string('frequency', 10)->default('daily');
            $table->unsignedTinyInteger('weekday')->default(1);
            $table->string('send_time', 5)->default('08:00');
        });
    }

    public function down(): void
    {
        Schema::table('vaccination_reminder_settings', function (Blueprint $table) {
            $table->dropColumn(['frequency', 'weekday', 'send_time']);
        });
    }
};
