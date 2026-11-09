<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vaccination_reminder_deliveries', function (Blueprint $table) {
            $table->date('reminder_date')->nullable()->after('vaccination_schedule_id');
        });

        DB::table('vaccination_reminder_deliveries')->orderBy('id')->chunkById(100, function ($deliveries) {
            foreach ($deliveries as $delivery) {
                $date = DB::table('vaccination_schedules')->where('id', $delivery->vaccination_schedule_id)
                    ->value(DB::raw('COALESCE(appointment_date, due_date)'));

                DB::table('vaccination_reminder_deliveries')->where('id', $delivery->id)
                    ->update(['reminder_date' => $date ?? '1970-01-01']);
            }
        });

        Schema::table('vaccination_reminder_deliveries', function (Blueprint $table) {
            $table->unique(['vaccination_schedule_id', 'reminder_date', 'days_before', 'email'], 'vaccination_reminder_delivery_date_unique');
        });
        Schema::table('vaccination_reminder_deliveries', function (Blueprint $table) {
            $table->dropUnique('vaccination_reminder_delivery_unique');
        });
    }

    public function down(): void
    {
        Schema::table('vaccination_reminder_deliveries', function (Blueprint $table) {
            $table->dropUnique('vaccination_reminder_delivery_date_unique');
            $table->dropColumn('reminder_date');
            $table->unique(['vaccination_schedule_id', 'days_before', 'email'], 'vaccination_reminder_delivery_unique');
        });
    }
};
