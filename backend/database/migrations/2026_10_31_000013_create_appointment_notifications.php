<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointment_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('child_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('title', 255);
            $table->enum('type', ['doctor_visit', 'medicine']);
            $table->dateTime('remind_at');
            $table->string('location', 500)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_completed')->default(false);
            $table->timestamp('notified_at')->nullable();
            $table->unsignedInteger('notification_revision')->default(1);
            $table->timestamps();
            $table->index(['is_completed', 'remind_at', 'notified_at'], 'appointment_reminders_due_index');
        });

        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('fcm_token');
            $table->char('token_hash', 64)->unique();
            $table->enum('device_type', ['android', 'ios']);
            $table->char('installation_id', 36)->unique();
            $table->timestamp('last_used_at');
            $table->timestamps();
            $table->index(['user_id', 'device_type']);
        });

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('vaccination_enabled')->default(true);
            $table->boolean('memory_enabled')->default(true);
            $table->boolean('milestone_enabled')->default(true);
            $table->boolean('appointment_enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_reminder_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('notification_revision');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('device_token_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->uuid('claim_token')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamps();
            $table->unique(['appointment_reminder_id', 'notification_revision', 'device_token_id'], 'notification_delivery_reminder_revision_device_unique');
            $table->index(['sent_at', 'failed_at', 'claimed_at'], 'notification_delivery_retry_index');
        });
    }

    public function down(): void
    {
        foreach (['notification_deliveries', 'notification_preferences', 'device_tokens', 'appointment_reminders'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
