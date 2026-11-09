<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('child_profiles', function (Blueprint $table) {
            $table->string('avatar_path')->nullable();
            $table->string('avatar_mime', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('child_profiles', fn (Blueprint $table) => $table->dropColumn(['avatar_path', 'avatar_mime']));
    }
};
