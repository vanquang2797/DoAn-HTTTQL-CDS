<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('milestones', function (Blueprint $t) {
            $t->id();
            $t->string('name', 100)->unique();
            $t->string('icon_code', 50);
            $t->timestamps();
        });
        Schema::create('memories', function (Blueprint $t) {
            $t->id();
            $t->foreignId('child_profile_id')->constrained()->cascadeOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->text('caption');
            $t->foreignId('milestone_id')->nullable()->constrained()->nullOnDelete();
            $t->date('memory_date');
            $t->enum('status', ['uploading', 'published', 'failed'])->default('uploading');
            // Durable private source manifest allows retry without uploading another memory.
            $t->json('source_manifest');
            $t->unsignedInteger('processing_generation')->default(1);
            $t->timestamps();
            $t->index(['child_profile_id', 'memory_date', 'id']);
        });
        Schema::create('media_files', function (Blueprint $t) {
            $t->id();
            $t->foreignId('memory_id')->constrained()->cascadeOnDelete();
            $t->string('file_path', 1000);
            $t->enum('file_type', ['image', 'video']);
            $t->string('mime_type', 40);
            $t->unsignedInteger('file_size_kb');
            $t->unsignedSmallInteger('duration_seconds')->nullable();
            $t->timestamps();
        });
        Schema::create('memory_reactions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('memory_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->enum('reaction_type', ['love', 'haha', 'wow']);
            $t->timestamps();
            $t->unique(['memory_id', 'user_id']);
        });
        Schema::create('storage_cleanups', function (Blueprint $t) {
            $t->id();
            $t->json('paths');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['storage_cleanups', 'memory_reactions', 'media_files', 'memories', 'milestones'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
