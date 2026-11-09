<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MilestoneTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        foreach ([
            ['name' => 'Đầy tháng', 'age_months' => 1, 'description' => 'Mốc đầy tháng của bé.', 'icon_code' => 'celebration', 'is_recurring' => false],
            ['name' => 'Ăn dặm', 'age_months' => 6, 'description' => 'Mốc bé bắt đầu ăn dặm.', 'icon_code' => 'restaurant', 'is_recurring' => false],
            ['name' => 'Thôi nôi / sinh nhật', 'age_months' => 12, 'description' => 'Thôi nôi và các mốc sinh nhật đến 5 tuổi dùng chung một dịp mỗi năm.', 'icon_code' => 'cake', 'is_recurring' => true],
        ] as $template) {
            DB::table('milestone_templates')->insertOrIgnore($template + ['created_at' => $now, 'updated_at' => $now]);
        }
    }
}
