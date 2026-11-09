<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('memories', function (Blueprint $table) {
            $table->uuid('processing_claim_token')->nullable()->after('processing_generation');
            $table->timestamp('processing_claimed_at')->nullable()->after('processing_claim_token');
        });
    }

    public function down(): void
    {
        Schema::table('memories', function (Blueprint $table) {
            $table->dropColumn(['processing_claim_token', 'processing_claimed_at']);
        });
    }
};
