<?php

namespace App\Console\Commands;

use App\Jobs\BuildChildProfileExport;
use App\Jobs\ProcessMemory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ReconcileMedia extends Command
{
    protected $signature = 'babi:reconcile-media';

    protected $description = 'Retry stale queued media and remove durably recorded private files';

    public function handle(): int
    {
        DB::table('child_profile_exports')->where('status', 'ready')->whereNotNull('expires_at')->where('expires_at', '<=', now())->orderBy('id')->chunkById(50, function ($exports) {
            foreach ($exports as $export) {
                if ($export->archive_path) {
                    DB::table('storage_cleanups')->insert(['paths' => json_encode([$export->archive_path], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
                }
                DB::table('child_profile_exports')->where('id', $export->id)->where('status', 'ready')->update(['status' => 'expired', 'archive_path' => null, 'updated_at' => now()]);
            }
        });
        DB::table('child_profile_exports')->whereIn('status', ['queued', 'processing'])->where('updated_at', '<', now()->subMinutes(5))->orderBy('id')->chunkById(50, function ($exports) {
            foreach ($exports as $export) {
                $claimed = DB::table('child_profile_exports')->where('id', $export->id)
                    ->whereIn('status', ['queued', 'processing'])->where('updated_at', '<', now()->subMinutes(5))
                    ->update(['status' => 'queued', 'updated_at' => now()]);
                if ($claimed) {
                    BuildChildProfileExport::dispatch($export->id);
                }
            }
        });
        DB::table('memories')->where('status', 'uploading')->where('updated_at', '<', now()->subMinutes(5))->orderBy('id')->chunkById(50, function ($items) {
            foreach ($items as $item) {
                $claimed = DB::table('memories')->where('id', $item->id)->where('status', 'uploading')
                    ->where('processing_generation', $item->processing_generation)->where('updated_at', '<', now()->subMinutes(5))
                    ->update(['processing_claim_token' => null, 'processing_claimed_at' => null, 'updated_at' => now()]);
                if ($claimed) {
                    ProcessMemory::dispatch($item->id, $item->processing_generation);
                }
            }
        });
        DB::table('storage_cleanups')->orderBy('id')->chunkById(50, function ($items) {
            foreach ($items as $item) {
                $paths = json_decode($item->paths, true, 512, JSON_THROW_ON_ERROR);
                if (! $paths || Storage::disk('local')->delete($paths)) {
                    DB::table('storage_cleanups')->where('id', $item->id)->delete();
                }
            }
        });

        return self::SUCCESS;
    }
}
