<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('milestone_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150)->unique();
            $table->unsignedSmallInteger('age_months');
            $table->text('description')->nullable();
            $table->string('icon_code', 50)->default('auto_awesome');
            $table->boolean('is_recurring')->default(false);
            $table->timestamps();
            $table->index(['age_months', 'is_recurring']);
        });

        Schema::create('milestone_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('child_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('milestone_template_id')->constrained()->restrictOnDelete();
            $table->date('reminder_date');
            $table->enum('status', ['pending', 'send_accepted', 'completed'])->default('pending');
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('notified_7d_at')->nullable();
            $table->timestamp('notified_1d_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->unique(['child_profile_id', 'milestone_template_id', 'reminder_date'], 'milestone_reminder_child_template_date_unique');
            $table->index(['reminder_date', 'status', 'cancelled_at'], 'milestone_reminder_due_index');
        });

        Schema::create('milestone_notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('milestone_reminder_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('days_before');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_token_id')->nullable()->constrained()->nullOnDelete();
            $table->char('device_key', 64);
            $table->enum('status', ['pending', 'processing', 'accepted', 'skipped', 'failed', 'unknown'])->default('pending');
            $table->unsignedTinyInteger('attempt_count')->default(0);
            $table->string('provider_message_id', 255)->nullable();
            $table->string('reason_code', 50)->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->uuid('claim_token')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('retry_after')->nullable();
            $table->timestamps();
            $table->unique(['milestone_reminder_id', 'days_before', 'user_id', 'device_key'], 'milestone_delivery_reminder_offset_user_device_unique');
            $table->index(['status', 'retry_after', 'claimed_at'], 'milestone_delivery_retry_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('milestone_notification_deliveries');
        Schema::dropIfExists('milestone_reminders');
        Schema::dropIfExists('milestone_templates');
    }
};
