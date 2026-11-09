<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('family_space_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_space_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['family_space_id', 'revoked_at', 'expires_at']);
        });

        Schema::create('family_join_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_space_id')->constrained()->cascadeOnDelete();
            $table->foreignId('family_space_link_id')->constrained('family_space_links')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('status', ['pending', 'approved', 'declined'])->default('pending');
            $table->timestamp('requested_at');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();
            $table->unique(['family_space_id', 'user_id']);
            $table->index(['family_space_id', 'status', 'requested_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('family_join_requests');
        Schema::dropIfExists('family_space_links');
    }
};
