<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('child_profiles', function (Blueprint $table) {
            $table->decimal('birth_weight_kg', 5, 2)->nullable();
            $table->decimal('birth_length_cm', 5, 1)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('child_profiles', function (Blueprint $table) {
            $table->dropColumn(['birth_weight_kg', 'birth_length_cm']);
        });
    }
};
