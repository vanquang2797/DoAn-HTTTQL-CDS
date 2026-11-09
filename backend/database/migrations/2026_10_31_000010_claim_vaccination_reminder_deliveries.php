<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vaccination_reminder_deliveries', function (Blueprint $table) {
            $table->uuid('claim_token')->nullable()->after('email');
            $table->timestamp('claimed_at')->nullable()->after('claim_token');
        });
    }

    public function down(): void
    {
        Schema::table('vaccination_reminder_deliveries', function (Blueprint $table) {
            $table->dropColumn(['claim_token', 'claimed_at']);
        });
    }
};
