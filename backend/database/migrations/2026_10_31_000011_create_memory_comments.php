<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memory_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('memory_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->text('content');
            $table->timestamps();
            $table->index(['memory_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memory_comments');
    }
};
