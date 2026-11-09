<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memory_notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('memory_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('device_token_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->uuid('claim_token')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamps();
            $table->unique(['memory_id', 'device_token_id'], 'memory_delivery_memory_device_unique');
            $table->index(['sent_at', 'failed_at', 'cancelled_at', 'claimed_at'], 'memory_delivery_retry_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memory_notification_deliveries');
    }
};
