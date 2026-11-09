<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('child_profile_exports', function (Blueprint $table) {
            $table->uuid('operation_key')->nullable()->after('requested_by');
            $table->timestamp('expires_at')->nullable()->change();
        });

        DB::table('child_profile_exports')->whereNull('operation_key')->orderBy('id')->chunkById(100, function ($exports) {
            foreach ($exports as $export) {
                DB::table('child_profile_exports')->where('id', $export->id)
                    ->update(['operation_key' => (string) Str::uuid()]);
            }
        });

        Schema::table('child_profile_exports', function (Blueprint $table) {
            $table->unique(['requested_by', 'operation_key'], 'child_profile_export_operation_unique');
        });
    }

    public function down(): void
    {
        DB::table('child_profile_exports')->whereNull('expires_at')->update(['expires_at' => now()->addDay()]);
        Schema::table('child_profile_exports', function (Blueprint $table) {
            $table->dropUnique('child_profile_export_operation_unique');
            $table->dropColumn('operation_key');
            $table->timestamp('expires_at')->nullable(false)->change();
        });
    }
};
