<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vaccination_push_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vaccination_schedule_id')->constrained()->cascadeOnDelete();
            $table->date('reminder_date');
            $table->unsignedSmallInteger('days_before');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('device_token_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->uuid('claim_token')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('retry_after')->nullable();
            $table->timestamps();
            $table->unique(['vaccination_schedule_id', 'reminder_date', 'days_before', 'device_token_id'], 'vaccination_push_schedule_date_days_device_unique');
            $table->index(['sent_at', 'failed_at', 'cancelled_at', 'claimed_at'], 'vaccination_push_retry_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vaccination_push_deliveries');
    }
};
