<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MilestoneSeeder extends Seeder
{
    public function run(): void
    {
        // Descriptive labels only: no recommended ages or clinical assessment.
        foreach (['Nụ cười đầu tiên', 'Lật người', 'Ngồi vững', 'Bước chân đầu tiên'] as $name) {
            DB::table('milestones')->insertOrIgnore(['name' => $name, 'icon_code' => 'auto_awesome', 'created_at' => now(), 'updated_at' => now()]);
        }
    }
}
