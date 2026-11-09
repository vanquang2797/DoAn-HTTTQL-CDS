<?php

namespace App\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class VaccineCatalogImporter
{
    /**
     * Import an immutable catalog. The caller is responsible for establishing
     * that the content has received an independent clinical review.
     *
     * @return array{schedule_version:string,program:string,template_count:int,effective_until:?string}
     */
    public function import(array $input): array
    {
        $data = Validator::make($input, [
            'schedule_version' => 'required|string|max:80',
            'program' => 'required|in:expanded,service',
            'effective_until' => 'nullable|date_format:Y-m-d',
            'templates' => 'required|array|min:1',
            'templates.*' => 'required|array:vaccine_code,name,dose_number,disease,timing_rule,age_value,age_unit,previous_template_id,min_interval_days,timing_note,source_url',
            'templates.*.vaccine_code' => 'required|string|max:60',
            'templates.*.name' => 'required|string|max:150',
            'templates.*.dose_number' => 'required|integer|min:1|max:65535',
            'templates.*.disease' => 'required|string|max:200',
            'templates.*.timing_rule' => 'required|in:age,clinical',
            'templates.*.age_value' => 'nullable|integer|min:0|max:65535',
            'templates.*.age_unit' => 'nullable|in:day,month',
            'templates.*.previous_template_id' => 'nullable|integer|exists:vaccine_templates,id',
            'templates.*.min_interval_days' => 'nullable|integer|min:1|max:65535',
            'templates.*.timing_note' => 'nullable|string|max:2000',
            'templates.*.source_url' => 'required|url:http,https|max:1000',
        ])->validate();
        $data['schedule_version'] = trim($data['schedule_version']);
        if ($data['schedule_version'] === '') {
            throw ValidationException::withMessages([
                'schedule_version' => 'Mã phiên bản không được để trống.',
            ]);
        }
        $data['effective_until'] = $data['effective_until'] ?? null;

        if ($data['effective_until'] !== null && $data['effective_until'] < today(config('babi.calendar_timezone'))->toDateString()) {
            throw ValidationException::withMessages([
                'effective_until' => 'Phiên bản catalog đã hết hạn; hãy review và nhập phiên bản đang có hiệu lực.',
            ]);
        }

        $seen = [];
        foreach ($data['templates'] as $index => $template) {
            $vaccineCode = trim($template['vaccine_code']);
            if ($vaccineCode === '') {
                throw ValidationException::withMessages([
                    "templates.$index.vaccine_code" => 'Mã vắc xin không được để trống.',
                ]);
            }
            $data['templates'][$index]['vaccine_code'] = $vaccineCode;

            $age = $template['age_value'] ?? null;
            $unit = $template['age_unit'] ?? null;
            if (($template['timing_rule'] === 'age' && ($age === null || $unit === null))
                || ($template['timing_rule'] === 'clinical' && ($age !== null || $unit !== null))) {
                throw ValidationException::withMessages([
                    "templates.$index.timing_rule" => 'Mẫu age cần mốc tuổi; mẫu clinical phải để tuổi rỗng.',
                ]);
            }

            // The configured MySQL/MariaDB collation is case-insensitive, so
            // detect identifiers the same way before relying on the unique index.
            $key = mb_strtolower($vaccineCode, 'UTF-8').':'.$template['dose_number'];
            if (isset($seen[$key])) {
                throw ValidationException::withMessages([
                    "templates.$index.dose_number" => 'Trùng mã vắc xin/thứ tự liều trong cùng phiên bản.',
                ]);
            }
            $seen[$key] = true;
        }

        try {
            DB::transaction(function () use ($data): void {
                if (DB::table('vaccine_templates')->where('program', $data['program'])
                    ->where('schedule_version', $data['schedule_version'])->exists()) {
                    throw ValidationException::withMessages([
                        'schedule_version' => 'Phiên bản đã tồn tại. Không ghi đè; hãy dùng phiên bản mới.',
                    ]);
                }

                foreach ($data['templates'] as $template) {
                    DB::table('vaccine_templates')->insert($template + [
                        'effective_until' => $data['effective_until'],
                        'program' => $data['program'],
                        'schedule_version' => $data['schedule_version'],
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }, 3);
        } catch (UniqueConstraintViolationException) {
            $versionExists = DB::table('vaccine_templates')->where('program', $data['program'])
                ->where('schedule_version', $data['schedule_version'])->exists();
            if ($versionExists) {
                throw ValidationException::withMessages([
                    'schedule_version' => 'Phiên bản đã tồn tại. Không ghi đè; hãy dùng phiên bản mới.',
                ]);
            }

            throw ValidationException::withMessages([
                'templates' => 'Mã vắc xin và thứ tự liều bị trùng trong cơ sở dữ liệu.',
            ]);
        }

        return [
            'schedule_version' => $data['schedule_version'],
            'program' => $data['program'],
            'template_count' => count($data['templates']),
            'effective_until' => $data['effective_until'],
        ];
    }
}
