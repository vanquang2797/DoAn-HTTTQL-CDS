<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vaccine_templates', function (Blueprint $table) {
            $table->date('effective_until')->nullable()->after('schedule_version');
        });
    }

    public function down(): void
    {
        Schema::table('vaccine_templates', function (Blueprint $table) {
            $table->dropColumn('effective_until');
        });
    }
};
