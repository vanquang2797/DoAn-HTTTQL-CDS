<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('family_invitation_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_space_id')->constrained()->cascadeOnDelete();
            $table->foreignId('proposed_by')->constrained('users')->restrictOnDelete();
            $table->string('email');
            $table->enum('status', ['pending', 'approved', 'declined'])->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('invitation_id')->nullable()->constrained('family_invitations')->nullOnDelete();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();
            $table->index(['family_space_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('family_invitation_proposals');
    }
};
