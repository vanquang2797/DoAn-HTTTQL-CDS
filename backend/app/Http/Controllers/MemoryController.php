<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessMemory;
use App\Services\FamilyAccess;
use App\Services\OperationService;
use App\Services\PrivateMedia;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class MemoryController extends Controller
{
    public function __construct(private FamilyAccess $access, private PrivateMedia $storage, private OperationService $operations) {}

    private function authorized(Request $r, int $id, bool $owner = false, bool $lock = false): object
    {
        $memory = DB::table('memories')->find($id);
        abort_unless($memory, 403, 'Không có quyền truy cập.');
        $child = $this->access->child($r->user(), $memory->child_profile_id, $owner, true, $lock);
        if ($lock) {
            $memory = DB::table('memories')->where('id', $id)->lockForUpdate()->first();
            abort_unless($memory, 403, 'Không có quyền truy cập.');
        }
        $member = $this->access->space($r->user(), $child->family_space_id);
        abort_if($memory->status !== 'published' && $member->role !== 'owner' && $memory->created_by !== $r->user()->id, 403, 'Không có quyền truy cập.');

        return $memory;
    }

    private function resource(object $memory, int $actor): array
    {
        $media = DB::table('media_files')->where('memory_id', $memory->id)->orderBy('id')->get()->map(fn ($file) => [
            'id' => $file->id, 'file_type' => $file->file_type, 'mime_type' => $file->mime_type,
            'url' => '/api/media-files/'.$file->id, 'file_size_kb' => $file->file_size_kb,
        ]);

        return [
            'id' => $memory->id, 'child_profile_id' => $memory->child_profile_id, 'caption' => $memory->caption,
            'memory_date' => $memory->memory_date, 'status' => $memory->status, 'milestone_id' => $memory->milestone_id,
            'milestone' => $memory->milestone_id ? DB::table('milestones')->where('id', $memory->milestone_id)->value('name') : null,
            'author' => DB::table('users')->where('id', $memory->created_by)->value('name'), 'media' => $media,
            'likes' => DB::table('memory_reactions')->where('memory_id', $memory->id)->count(),
            'liked' => DB::table('memory_reactions')->where('memory_id', $memory->id)->where('user_id', $actor)->exists(),
            'comments' => DB::table('memory_comments')->where('memory_id', $memory->id)->count(),
        ];
    }

    private function commentResource(object $comment, int $actor): array
    {
        return [
            'id' => $comment->id,
            'memory_id' => $comment->memory_id,
            'content' => $comment->content,
            'author' => $comment->author,
            'is_mine' => (int) $comment->user_id === $actor,
            'created_at' => $comment->created_at,
            'updated_at' => $comment->updated_at,
        ];
    }

    public function milestones()
    {
        return response()->json(['success' => true, 'data' => DB::table('milestones')->orderBy('id')->get(['id', 'name', 'icon_code'])]);
    }

    public function index(Request $r, int $id)
    {
        $child = $this->access->child($r->user(), $id);
        $member = $this->access->space($r->user(), $child->family_space_id);
        $r->validate(['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100', 'milestone_id' => 'sometimes|integer|min:1']);
        $query = DB::table('memories')->where('child_profile_id', $id);
        if ($member->role !== 'owner') {
            $query->where(fn ($q) => $q->where('status', 'published')->orWhere('created_by', $r->user()->id));
        }
        if ($r->filled('milestone_id')) {
            $query->where('milestone_id', $r->integer('milestone_id'));
        }
        $items = $query->orderByDesc('memory_date')->orderByDesc('id')->paginate($r->integer('per_page', 20));
        $items->through(fn ($memory) => $this->resource($memory, $r->user()->id));

        return response()->json(['success' => true, 'data' => $items]);
    }

    public function show(Request $r, int $id)
    {
        return response()->json(['success' => true, 'data' => $this->resource($this->authorized($r, $id), $r->user()->id)]);
    }

    public function store(Request $r)
    {
        $r->merge(['memory_date' => $r->input('memory_date', CarbonImmutable::today(config('babi.calendar_timezone'))->toDateString())]);
        $data = $r->validate([
            'child_profile_id' => 'required|integer', 'caption' => 'required|string|max:2000',
            'memory_date' => 'required|date_format:Y-m-d', 'milestone_id' => 'nullable|integer|exists:milestones,id',
            'files' => 'required|array|min:1|max:10', 'files.*' => 'required|file|mimetypes:image/jpeg,image/png,video/mp4,video/webm|max:51200',
        ]);
        Validator::make(['key' => $r->header('X-Operation-Key')], ['key' => 'required|uuid'])->validate();
        $this->access->child($r->user(), $data['child_profile_id']);
        $files = $r->file('files');
        $fingerprints = [];
        foreach ($files as $i => $file) {
            $mime = $file->getMimeType();
            Validator::make(['file' => $file], ['file' => str_starts_with($mime, 'video/') ? 'max:51200' : 'max:10240'])->validate();
            $fingerprints[] = ['sha256' => hash_file('sha256', $file->getRealPath()), 'mime' => $mime];
        }
        unset($data['files']);
        $sources = [];
        try {
            $result = DB::transaction(function () use ($r, $data, $files, $fingerprints, &$sources) {
                return $this->operations->run($r->user(), $r->header('X-Operation-Key'), 'memory.create', $data + ['files' => $fingerprints], function () use ($r, $data, $files, $fingerprints, &$sources) {
                    $child = $this->access->child($r->user(), $data['child_profile_id'], false, true, true);
                    abort_if($data['memory_date'] < $child->date_of_birth->toDateString() || $data['memory_date'] > CarbonImmutable::today(config('babi.calendar_timezone'))->toDateString(), 422, 'Ngày kỷ niệm phải từ ngày sinh đến hôm nay.');
                    foreach ($files as $i => $file) {
                        $path = $file->store('memory-sources', 'local');
                        if (! $path) {
                            throw new \RuntimeException('SOURCE_WRITE_FAILED');
                        }
                        $sources[] = ['path' => $path, 'mime' => $fingerprints[$i]['mime']];
                    }
                    $id = DB::table('memories')->insertGetId($data + ['created_by' => $r->user()->id, 'status' => 'uploading', 'source_manifest' => json_encode($sources, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
                    $child->increment('data_revision');

                    return $id;
                }, fn ($op) => $this->authorized($r, $op->resource_id), 'memory');
            });
        } catch (\Throwable $e) {
            $this->storage->cleanup(array_column($sources, 'path'));
            throw $e;
        }
        // Dispatch failure must not turn a committed write into a reported failed creation.
        // babi:reconcile-media can enqueue this durable uploading record again.
        if (! $result['replayed']) {
            try {
                ProcessMemory::dispatch($result['id'], 1);
            } catch (\Throwable $e) {
                // The manifest is retained for reconciliation.
            }
        }

        return response()->json(['success' => true, 'data' => $this->resource(DB::table('memories')->find($result['id']), $r->user()->id), 'replayed' => $result['replayed']], 201);
    }

    public function retry(Request $r, int $id)
    {
        $generation = DB::transaction(function () use ($r, $id) {
            $memory = $this->authorized($r, $id, false, true);
            $child = $this->access->child($r->user(), $memory->child_profile_id);
            $member = $this->access->space($r->user(), $child->family_space_id);
            abort_unless($member->role === 'owner' || $memory->created_by === $r->user()->id, 403, 'Chỉ được thử lại bài của bạn.');
            abort_unless($memory->status === 'failed', 409, 'Chỉ thử lại kỷ niệm xử lý lỗi.');
            $generation = $memory->processing_generation + 1;
            DB::table('memories')->where('id', $id)->update(['status' => 'uploading', 'processing_generation' => $generation, 'processing_claim_token' => null, 'processing_claimed_at' => null, 'updated_at' => now()]);
            DB::table('child_profiles')->where('id', $memory->child_profile_id)->increment('data_revision');

            return $generation;
        });
        try {
            ProcessMemory::dispatch($id, $generation);
        } catch (\Throwable $e) {
        }

        return $this->show($r, $id);
    }

    public function update(Request $r, int $id)
    {
        $data = $r->validate(['caption' => 'required|string|max:2000', 'milestone_id' => 'nullable|integer|exists:milestones,id', 'expected_data_revision' => 'required|integer|min:0']);
        DB::transaction(function () use ($r, $id, $data) {
            $memory = $this->authorized($r, $id, true, true);
            $child = DB::table('child_profiles')->find($memory->child_profile_id);
            abort_unless($child->data_revision === (int) $data['expected_data_revision'], 409, 'Dữ liệu đã thay đổi.');
            unset($data['expected_data_revision']);
            DB::table('memories')->where('id', $id)->update($data + ['updated_at' => now()]);
            DB::table('child_profiles')->where('id', $child->id)->increment('data_revision');
        });

        return $this->show($r, $id);
    }

    public function destroy(Request $r, int $id)
    {
        $data = $r->validate(['confirmed' => 'required|accepted', 'expected_data_revision' => 'required|integer|min:0']);
        DB::transaction(function () use ($r, $id, $data) {
            $memory = $this->authorized($r, $id, true, true);
            $child = DB::table('child_profiles')->find($memory->child_profile_id);
            abort_unless($child->data_revision === (int) $data['expected_data_revision'], 409, 'Dữ liệu đã thay đổi.');
            $this->storage->cleanup(array_merge(array_column(json_decode($memory->source_manifest, true), 'path'), DB::table('media_files')->where('memory_id', $id)->pluck('file_path')->all()));
            DB::table('memories')->where('id', $id)->delete();
            DB::table('child_profiles')->where('id', $child->id)->increment('data_revision');
        });

        return response()->json(['success' => true, 'message' => 'Đã xóa kỷ niệm, tệp được dọn ở nền.'], 202);
    }

    public function media(Request $r, int $id)
    {
        $file = DB::table('media_files')->find($id);
        abort_unless($file, 403, 'Không có quyền truy cập.');
        $memory = $this->authorized($r, $file->memory_id);
        abort_unless($memory->status === 'published', 403, 'Không có quyền truy cập.');
        abort_unless(Storage::disk('local')->exists($file->file_path), 404, 'Tệp không còn khả dụng.');

        $stream = Storage::disk('local')->readStream($file->file_path);
        abort_unless(is_resource($stream), 404, 'Tệp không còn khả dụng.');

        return response()->stream(function () use ($stream) {
            fpassthru($stream);
            fclose($stream);
        }, 200, ['Content-Type' => $file->mime_type, 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function reaction(Request $r, int $id)
    {
        $data = $r->validate(['reaction_type' => 'required|in:love,haha,wow']);
        DB::transaction(function () use ($r, $id, $data) {
            $memory = $this->authorized($r, $id, false, true);
            abort_unless($memory->status === 'published', 409, 'Kỷ niệm chưa được công bố.');
            $existing = DB::table('memory_reactions')->where('memory_id', $id)->where('user_id', $r->user()->id)->first();
            if ($existing && $existing->reaction_type === $data['reaction_type']) {
                DB::table('memory_reactions')->where('id', $existing->id)->delete();
            } else {
                DB::table('memory_reactions')->updateOrInsert(['memory_id' => $id, 'user_id' => $r->user()->id], $data + ['created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('child_profiles')->where('id', $memory->child_profile_id)->increment('data_revision');
        });

        return $this->show($r, $id);
    }

    public function comments(Request $r, int $id)
    {
        $r->validate(['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100']);
        $memory = $this->authorized($r, $id);
        abort_unless($memory->status === 'published', 409, 'Kỷ niệm chưa được công bố.');
        $comments = DB::table('memory_comments')
            ->join('users', 'users.id', '=', 'memory_comments.user_id')
            ->where('memory_comments.memory_id', $id)
            ->orderBy('memory_comments.id')
            ->select('memory_comments.*', 'users.name as author')
            ->paginate($r->integer('per_page', 30));
        $comments->through(fn ($comment) => $this->commentResource($comment, $r->user()->id));

        return response()->json(['success' => true, 'data' => $comments]);
    }

    public function comment(Request $r, int $id)
    {
        $r->merge(['content' => trim((string) $r->input('content'))]);
        $data = $r->validate(['content' => 'required|string|min:1|max:500']);
        $commentId = DB::transaction(function () use ($r, $id, $data) {
            $memory = $this->authorized($r, $id, false, true);
            abort_unless($memory->status === 'published', 409, 'Kỷ niệm chưa được công bố.');
            $commentId = DB::table('memory_comments')->insertGetId([
                'memory_id' => $id,
                'user_id' => $r->user()->id,
                'content' => $data['content'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('child_profiles')->where('id', $memory->child_profile_id)->increment('data_revision');

            return $commentId;
        }, 3);
        $comment = DB::table('memory_comments')->join('users', 'users.id', '=', 'memory_comments.user_id')
            ->where('memory_comments.id', $commentId)->select('memory_comments.*', 'users.name as author')->first();

        return response()->json(['success' => true, 'data' => $this->commentResource($comment, $r->user()->id)], 201);
    }

    public function deleteComment(Request $r, int $id)
    {
        DB::transaction(function () use ($r, $id) {
            $comment = DB::table('memory_comments')->find($id);
            abort_unless($comment, 403, 'Không có quyền truy cập.');
            $memory = $this->authorized($r, $comment->memory_id, false, true);
            abort_unless($memory->status === 'published', 409, 'Kỷ niệm chưa được công bố.');
            $child = $this->access->child($r->user(), $memory->child_profile_id);
            $member = $this->access->space($r->user(), $child->family_space_id);
            abort_unless($member->role === 'owner' || (int) $comment->user_id === (int) $r->user()->id, 403, 'Bạn chỉ có thể xóa bình luận của mình.');
            DB::table('memory_comments')->where('id', $id)->delete();
            DB::table('child_profiles')->where('id', $child->id)->increment('data_revision');
        }, 3);

        return response()->json(['success' => true, 'message' => 'Đã xóa bình luận.']);
    }
}
