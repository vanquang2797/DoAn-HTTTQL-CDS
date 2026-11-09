<?php

namespace App\Console\Commands;

use App\Services\VaccineCatalogImporter;
use Illuminate\Console\Command;

class ImportVaccineCatalog extends Command
{
    protected $signature = 'babi:import-vaccines {file : Path to reviewed JSON catalog} {--reviewed : Confirm the catalog has been independently reviewed}';

    protected $description = 'Import an immutable, reviewed vaccine catalog version; does not invent clinical data';

    public function handle(VaccineCatalogImporter $importer): int
    {
        if (! $this->option('reviewed')) {
            $this->error('Chỉ nhập bộ danh mục đã được kiểm chứng; cần --reviewed.');

            return self::FAILURE;
        }
        try {
            $path = $this->argument('file');
            if (! is_file($path)) {
                throw new \RuntimeException('Không tìm thấy tệp JSON.');
            }
            $data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($data) || array_is_list($data)) {
                throw new \RuntimeException('Tệp JSON phải chứa một object catalog.');
            }
            $result = $importer->import($data);
            $this->info('Đã nhập danh mục '.$result['schedule_version'].' ('.$result['template_count'].' mẫu). Admin cần chọn phiên bản sau khi rà soát.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
