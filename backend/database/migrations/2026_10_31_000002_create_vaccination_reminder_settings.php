<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vaccination_reminder_settings', function (Blueprint $table) {
            $table->foreignId('family_space_id')->primary()->constrained()->cascadeOnDelete();
            $table->json('days_before')->default('[7]');
            $table->json('recipient_emails')->nullable();
            $table->timestamps();
        });
        Schema::create('vaccination_reminder_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vaccination_schedule_id')->constrained('vaccination_schedules')->cascadeOnDelete();
            $table->unsignedSmallInteger('days_before');
            $table->string('email');
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['vaccination_schedule_id', 'days_before', 'email'], 'vaccination_reminder_delivery_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vaccination_reminder_deliveries');
        Schema::dropIfExists('vaccination_reminder_settings');
    }
};
