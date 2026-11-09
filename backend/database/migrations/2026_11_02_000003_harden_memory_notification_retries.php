<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('memory_notification_deliveries', function (Blueprint $table) {
            $table->unsignedTinyInteger('attempt_count')->default(0)->after('claimed_at');
            $table->timestamp('retry_after')->nullable()->after('attempt_count');
            $table->timestamp('unknown_at')->nullable()->after('retry_after');
            $table->string('reason_code', 50)->nullable()->after('unknown_at');
            $table->index(['retry_after', 'sent_at', 'failed_at', 'cancelled_at'], 'memory_delivery_backoff_index');
        });
    }

    public function down(): void
    {
        Schema::table('memory_notification_deliveries', function (Blueprint $table) {
            $table->dropIndex('memory_delivery_backoff_index');
            $table->dropColumn(['attempt_count', 'retry_after', 'unknown_at', 'reason_code']);
        });
    }
};
