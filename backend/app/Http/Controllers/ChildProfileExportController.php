<?php

namespace App\Http\Controllers;

use App\Jobs\BuildChildProfileExport;
use App\Models\User;
use App\Services\FamilyAccess;
use App\Services\PrivateMedia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class ChildProfileExportController extends Controller
{
    public function __construct(private FamilyAccess $access, private PrivateMedia $storage) {}

    public function store(Request $request, int $id)
    {
        $request->merge(['operation_key' => $request->header('X-Operation-Key')]);
        $data = $request->validate([
            'current_password' => 'required|string|max:255',
            'operation_key' => 'required|uuid',
        ]);
        $result = DB::transaction(function () use ($request, $id, $data) {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless(Hash::check($data['current_password'], $user->password), 422, 'Mật khẩu hiện tại không chính xác.');
            $child = $this->access->child($user, $id, true, false, true);
            $key = strtolower($data['operation_key']);
            $existing = DB::table('child_profile_exports')->where('requested_by', $user->id)->where('operation_key', $key)->first();
            if ($existing) {
                abort_unless((int) $existing->child_profile_id === $id, 409, 'Mã thao tác đã dùng cho hồ sơ khác.');

                return ['id' => $existing->id, 'created' => false];
            }
            abort_if(DB::table('memories')->where('child_profile_id', $id)->where('status', 'uploading')->exists(), 409, 'Đang xử lý kỷ niệm. Hãy đợi hoàn tất rồi yêu cầu ZIP.');
            $exportId = DB::table('child_profile_exports')->insertGetId([
                'child_profile_id' => $id,
                'requested_by' => $user->id,
                'operation_key' => $key,
                'snapshot_data_revision' => $child->data_revision,
                'status' => 'queued',
                'expires_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return ['id' => $exportId, 'created' => true];
        }, 3);
        if ($result['created']) {
            try {
                BuildChildProfileExport::dispatch($result['id']);
            } catch (\Throwable $exception) {
                // The export request is durable; reconcile-media will dispatch
                // it again if the queue connection was unavailable.
                report($exception);
            }
        }

        return response()->json(['success' => true, 'data' => DB::table('child_profile_exports')->find($result['id']), 'replayed' => ! $result['created']], 202);
    }

    public function show(Request $request, int $id)
    {
        $export = DB::table('child_profile_exports')->find($id);
        abort_unless($export, 404, 'Không tìm thấy yêu cầu xuất dữ liệu.');
        abort_unless((int) $export->requested_by === (int) $request->user()->id, 403, 'Không có quyền truy cập.');
        $this->access->child($request->user(), $export->child_profile_id, true, false);

        return response()->json(['success' => true, 'data' => $this->expireIfNeeded($export)]);
    }

    public function download(Request $request, int $id)
    {
        $export = DB::table('child_profile_exports')->find($id);
        abort_unless($export, 404, 'Không tìm thấy bản xuất.');
        abort_unless((int) $export->requested_by === (int) $request->user()->id, 403, 'Không có quyền truy cập.');
        $this->access->child($request->user(), $export->child_profile_id, true, false);
        $export = $this->expireIfNeeded($export);
        abort_if($export->status === 'expired', 410, 'Liên kết tải ZIP đã hết hạn.');
        abort_unless($export->status === 'ready' && $export->archive_path, 409, 'Bản ZIP chưa sẵn sàng.');
        $disk = Storage::disk('local');
        abort_unless($disk->exists($export->archive_path), 410, 'Tệp ZIP đã hết hạn hoặc không còn khả dụng.');
        $stream = $disk->readStream($export->archive_path);
        abort_unless(is_resource($stream), 410, 'Tệp ZIP không còn khả dụng.');

        return response()->streamDownload(function () use ($stream) {
            fpassthru($stream);
            fclose($stream);
        }, 'babi-child-'.$export->child_profile_id.'-export.zip', [
            'Content-Type' => 'application/zip',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function expireIfNeeded(object $export): object
    {
        if ($export->status === 'ready' && $export->expires_at && now()->greaterThanOrEqualTo($export->expires_at)) {
            $this->storage->cleanup(array_filter([$export->archive_path]));
            DB::table('child_profile_exports')->where('id', $export->id)->update(['status' => 'expired', 'archive_path' => null, 'updated_at' => now()]);
            $export = DB::table('child_profile_exports')->find($export->id);
        }

        return $export;
    }
}
