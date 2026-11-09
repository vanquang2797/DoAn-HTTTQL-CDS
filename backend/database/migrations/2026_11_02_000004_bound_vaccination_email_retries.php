<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vaccination_reminder_deliveries', function (Blueprint $table) {
            $table->unsignedTinyInteger('attempt_count')->default(0)->after('retry_after');
            $table->timestamp('failed_at')->nullable()->after('attempt_count');
            $table->string('reason_code', 50)->nullable()->after('failed_at');
            $table->index(['retry_after', 'sent_at', 'failed_at'], 'vaccination_email_retry_index');
        });
    }

    public function down(): void
    {
        Schema::table('vaccination_reminder_deliveries', function (Blueprint $table) {
            $table->dropIndex('vaccination_email_retry_index');
            $table->dropColumn(['attempt_count', 'failed_at', 'reason_code']);
        });
    }
};
