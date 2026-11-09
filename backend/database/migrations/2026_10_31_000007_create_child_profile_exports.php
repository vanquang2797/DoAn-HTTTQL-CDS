<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('child_profile_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('child_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('snapshot_data_revision');
            $table->string('status', 20)->default('queued');
            $table->string('archive_path')->nullable();
            $table->timestamp('expires_at');
            $table->text('failure_reason')->nullable();
            $table->timestamps();
            $table->index(['child_profile_id', 'requested_by', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('child_profile_exports');
    }
};
