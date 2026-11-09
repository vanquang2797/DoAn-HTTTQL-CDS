<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $checks = [
        'health_records' => ['health_positive' => 'height_cm > 0 AND weight_kg > 0'],
        'vaccine_templates' => [
            'template_dose_positive' => 'dose_number > 0',
            'template_timing_valid' => "(timing_rule = 'age' AND age_value IS NOT NULL AND age_unit IS NOT NULL) OR (timing_rule = 'clinical' AND age_value IS NULL AND age_unit IS NULL)",
        ],
        'vaccination_records' => [
            'record_date_precision_valid' => "(date_precision = 'exact' AND administered_date IS NOT NULL) OR (date_precision = 'unknown' AND administered_date IS NULL)",
            'record_dose_positive' => 'dose_number IS NULL OR dose_number > 0',
        ],
        'child_data_consents' => [
            'consent_representative_confirmed' => 'representative_confirmed = 1',
            'consent_dates_valid' => 'withdrawn_at IS NULL OR withdrawn_at >= granted_at',
        ],
    ];

    public function up(): void
    {
        // SQLite tests exercise API validation and UNIQUE/FK, not MySQL CHECK acceptance.
        if (DB::getDriverName() !== 'mysql') {
            return;
        }
        foreach ($this->checks as $table => $checks) {
            foreach ($checks as $name => $expression) {
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression})");
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }
        foreach ($this->checks as $table => $checks) {
            foreach ($checks as $name => $expression) {
                DB::statement("ALTER TABLE {$table} DROP CHECK {$name}");
            }
        }
    }
};
