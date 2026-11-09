<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\FamilyAccess;
use App\Services\PrivateMedia;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

class BuildChildProfileExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 180;

    public function __construct(public int $exportId) {}

    public function handle(FamilyAccess $access, PrivateMedia $storage): void
    {
        $export = DB::table('child_profile_exports')->find($this->exportId);
        if (! $export || $export->status === 'ready' || $export->status === 'expired') {
            return;
        }
        $disk = Storage::disk('local');
        $path = 'exports/'.Str::uuid().'.zip';
        $disk->makeDirectory('exports');

        try {
            DB::table('child_profile_exports')->where('id', $this->exportId)->update(['status' => 'processing', 'updated_at' => now()]);
            $owner = User::find($export->requested_by);
            abort_unless($owner, 403, 'Requester is unavailable.');
            $child = $access->child($owner, $export->child_profile_id, true, false);

            $memories = DB::table('memories')->where('child_profile_id', $child->id)->orderBy('id')->get();
            abort_if($memories->contains(fn ($memory) => $memory->status === 'uploading'), 409, 'Media processing is not finished.');
            $memoryRows = [];
            $mediaRows = [];
            $avatarEntry = null;
            if ($child->avatar_path && $disk->exists($child->avatar_path)) {
                $extension = pathinfo($child->avatar_path, PATHINFO_EXTENSION) ?: 'bin';
                $avatarEntry = 'media/child-avatar.'.$extension;
                $mediaRows[] = [
                    'path' => $child->avatar_path,
                    'entry' => $avatarEntry,
                    'metadata' => ['type' => 'avatar', 'mime_type' => $child->avatar_mime],
                ];
            }
            foreach ($memories as $memory) {
                $memoryRows[] = [
                    'id' => $memory->id,
                    'caption' => $memory->caption,
                    'date' => $memory->memory_date,
                    'milestone' => $memory->milestone_id ? DB::table('milestones')->where('id', $memory->milestone_id)->value('name') : null,
                    'status' => $memory->status,
                    'created_by' => DB::table('users')->where('id', $memory->created_by)->value('name'),
                    'created_at' => $memory->created_at,
                ];
                foreach (DB::table('media_files')->where('memory_id', $memory->id)->orderBy('id')->get() as $media) {
                    $mediaRows[] = [
                        'path' => $media->file_path,
                        'entry' => 'media/memory-'.$memory->id.'-file-'.$media->id.'.'.pathinfo($media->file_path, PATHINFO_EXTENSION),
                        'metadata' => ['memory_id' => $memory->id, 'type' => $media->file_type, 'mime_type' => $media->mime_type, 'size_kb' => $media->file_size_kb, 'duration_seconds' => $media->duration_seconds],
                    ];
                }
                if ($memory->status === 'failed') {
                    foreach (json_decode($memory->source_manifest, true, 512, JSON_THROW_ON_ERROR) as $index => $source) {
                        if ($disk->exists($source['path'])) {
                            $extension = pathinfo($source['path'], PATHINFO_EXTENSION) ?: 'bin';
                            $mediaRows[] = [
                                'path' => $source['path'],
                                'entry' => 'media/memory-'.$memory->id.'-original-'.($index + 1).'.'.$extension,
                                'metadata' => ['memory_id' => $memory->id, 'type' => str_starts_with($source['mime'], 'video/') ? 'video' : 'image', 'mime_type' => $source['mime'], 'original' => true],
                            ];
                        }
                    }
                }
            }

            $payload = [
                'exported_at' => now()->toIso8601String(),
                'snapshot_data_revision' => (int) $export->snapshot_data_revision,
                'child' => $child->only(['id', 'name', 'date_of_birth', 'gender', 'blood_type', 'created_at', 'updated_at']) + ['avatar_archive_path' => $avatarEntry],
                'consents' => DB::table('child_data_consents')->where('child_profile_id', $child->id)->orderBy('id')->get(['given_by', 'purpose', 'policy_version', 'representative_confirmed', 'granted_at', 'withdrawn_at']),
                'health_records' => DB::table('health_records')->where('child_profile_id', $child->id)->orderBy('measured_at')->orderBy('id')->get(),
                'vaccination_schedule' => DB::table('vaccination_schedules')->join('vaccine_templates', 'vaccine_templates.id', '=', 'vaccination_schedules.vaccine_template_id')->where('child_profile_id', $child->id)->orderBy('vaccination_schedules.id')->get(['vaccination_schedules.due_date', 'vaccination_schedules.appointment_date', 'vaccination_schedules.appointment_source', 'vaccination_schedules.status', 'vaccine_templates.vaccine_code', 'vaccine_templates.name', 'vaccine_templates.dose_number', 'vaccine_templates.schedule_version', 'vaccine_templates.timing_note']),
                'vaccination_records' => DB::table('vaccination_records')->where('child_profile_id', $child->id)->orderBy('id')->get(),
                'vaccination_record_changes' => DB::table('vaccination_records')->join('vaccination_record_changes', 'vaccination_record_changes.vaccination_record_id', '=', 'vaccination_records.id')->where('vaccination_records.child_profile_id', $child->id)->orderBy('vaccination_record_changes.id')->get(['vaccination_record_changes.*']),
                'memories' => $memoryRows,
                'reactions' => DB::table('memory_reactions')->join('memories', 'memories.id', '=', 'memory_reactions.memory_id')->where('memories.child_profile_id', $child->id)->orderBy('memory_reactions.id')->get(['memory_reactions.memory_id', 'memory_reactions.user_id', 'memory_reactions.reaction_type', 'memory_reactions.created_at']),
                'comments' => DB::table('memory_comments')->join('memories', 'memories.id', '=', 'memory_comments.memory_id')->where('memories.child_profile_id', $child->id)->orderBy('memory_comments.id')->get(['memory_comments.memory_id', 'memory_comments.user_id', 'memory_comments.content', 'memory_comments.created_at', 'memory_comments.updated_at']),
                'media_files' => collect($mediaRows)->map(fn ($media) => ['archive_path' => $media['entry']] + $media['metadata'])->all(),
            ];

            $archive = new ZipArchive;
            $absolutePath = $disk->path($path);
            $opened = $archive->open($absolutePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            if ($opened !== true) {
                throw new \RuntimeException('Could not create export archive.');
            }
            $archive->addFromString('data.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $archive->addFromString('README.txt', "Babi profile export\nSnapshot revision: {$export->snapshot_data_revision}\nFiles under media/ are private copies.\n");
            foreach ($mediaRows as $media) {
                if (! $archive->addFile($disk->path($media['path']), $media['entry'])) {
                    throw new \RuntimeException('Could not add media to export archive.');
                }
            }
            if (! $archive->close() || ! $disk->exists($path)) {
                throw new \RuntimeException('Could not finalize export archive.');
            }

            $ready = DB::transaction(function () use ($access, $owner, $export, $path) {
                $child = $access->child($owner->fresh(), $export->child_profile_id, true, false, true);
                $current = DB::table('child_profile_exports')->where('id', $this->exportId)->lockForUpdate()->first();
                if (! $current || $current->status !== 'processing') {
                    return false;
                }
                if ($child->data_revision !== (int) $export->snapshot_data_revision) {
                    DB::table('child_profile_exports')->where('id', $this->exportId)->update(['status' => 'failed', 'failure_reason' => 'Dữ liệu đã thay đổi trong lúc tạo bản xuất. Hãy tạo yêu cầu mới.', 'updated_at' => now()]);

                    return false;
                }
                DB::table('child_profile_exports')->where('id', $this->exportId)->update(['status' => 'ready', 'archive_path' => $path, 'expires_at' => now()->addHours(24), 'failure_reason' => null, 'updated_at' => now()]);

                return true;
            }, 3);
            if (! $ready) {
                $storage->cleanup([$path]);
            }
        } catch (\Throwable $exception) {
            $storage->cleanup([$path]);
            throw $exception;
        }
    }

    public function failed(?\Throwable $exception): void
    {
        DB::table('child_profile_exports')->where('id', $this->exportId)->whereIn('status', ['queued', 'processing'])->update([
            'status' => 'failed',
            'failure_reason' => 'Không thể tạo bản xuất. Hãy thử lại.',
            'updated_at' => now(),
        ]);
    }
}
