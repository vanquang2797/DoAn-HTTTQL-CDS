<?php

namespace Tests\Feature;

use App\Jobs\ProcessMemory;
use App\Models\ChildProfile;
use App\Models\User;
use App\Services\FamilyAccess;
use App\Services\FcmClient;
use App\Services\FcmDeliveryUnknownException;
use App\Services\FcmRetryableException;
use App\Services\PrivateMedia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class TimelineTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private int $child;

    private int $space;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();
        $this->owner = User::factory()->create();
        $this->space = DB::table('family_spaces')->insertGetId(['owner_user_id' => $this->owner->id, 'name' => 'Test family', 'created_at' => now(), 'updated_at' => now()]);
        $this->member($this->owner, 'owner');
        $this->child = ChildProfile::create(['user_id' => $this->owner->id, 'family_space_id' => $this->space, 'name' => 'Test child', 'date_of_birth' => now()->subMonths(2)->toDateString(), 'gender' => 'female', 'consent_revision' => 1, 'data_revision' => 1])->id;
        DB::table('child_data_consents')->insert(['child_profile_id' => $this->child, 'given_by' => $this->owner->id, 'purpose' => 'storage', 'policy_version' => 'test', 'representative_confirmed' => true, 'granted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        Sanctum::actingAs($this->owner);
    }

    private function member(User $user, string $role = 'viewer'): void
    {
        DB::table('family_members')->insert(['family_space_id' => $this->space, 'user_id' => $user->id, 'role' => $role, 'joined_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function upload(?string $key = null, array $overrides = [])
    {
        return $this->post('/api/memories', array_replace(['child_profile_id' => $this->child, 'caption' => 'A memory', 'memory_date' => now()->toDateString(), 'files' => [UploadedFile::fake()->image('photo.png', 1800, 900)]], $overrides), ['Accept' => 'application/json', 'X-Operation-Key' => $key ?? (string) Str::uuid()]);
    }

    private function process(int $id): void
    {
        $job = new ProcessMemory($id, 1);
        $job->handle(app(FamilyAccess::class), app(PrivateMedia::class));
    }

    private function addDevice(User $user, string $token): void
    {
        DB::table('device_tokens')->insert([
            'user_id' => $user->id,
            'fcm_token' => Crypt::encryptString($token),
            'token_hash' => hash('sha256', $token),
            'device_type' => 'android',
            'installation_id' => (string) Str::uuid(),
            'last_used_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function configureFcmMock(): void
    {
        $clientEmail = 'memory-'.Str::uuid().'@babi.invalid';
        $projectId = 'babi-test-project';
        $credentialPath = tempnam(sys_get_temp_dir(), 'babi-fcm-');
        file_put_contents($credentialPath, json_encode([
            'client_email' => $clientEmail, 'private_key' => 'test-key-unused-with-cached-token',
            'project_id' => $projectId, 'token_uri' => 'https://oauth2.googleapis.com/token',
        ], JSON_THROW_ON_ERROR));
        $this->beforeApplicationDestroyed(fn () => @unlink($credentialPath));
        config(['services.fcm.credentials' => $credentialPath, 'services.fcm.project_id' => $projectId]);
        Cache::put('babi:fcm:oauth:'.hash('sha256', $clientEmail.'|'.$projectId), 'test-oauth-token', now()->addMinutes(30));
        Http::fake(fn () => Http::response(['name' => 'projects/babi-test-project/messages/accepted'], 200));
    }

    public function test_published_memory_notifies_other_enabled_family_members_once(): void
    {
        $viewer = User::factory()->create();
        $this->member($viewer);
        $this->addDevice($viewer, 'memory-notification-device-token-001');
        $disabled = User::factory()->create();
        $this->member($disabled);
        $this->addDevice($disabled, 'memory-notification-device-token-002');
        DB::table('notification_preferences')->insert([
            'user_id' => $disabled->id, 'memory_enabled' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $memoryId = $this->upload()->assertCreated()->json('data.id');
        $this->process($memoryId);
        $this->assertDatabaseCount('memory_notification_deliveries', 1);
        $deliveryId = DB::table('memory_notification_deliveries')->where('memory_id', $memoryId)->value('id');
        $this->assertDatabaseHas('memory_notification_deliveries', [
            'id' => $deliveryId, 'user_id' => $viewer->id, 'sent_at' => null,
        ]);
        $this->assertDatabaseMissing('memory_notification_deliveries', ['user_id' => $this->owner->id]);
        $this->assertDatabaseMissing('memory_notification_deliveries', ['user_id' => $disabled->id]);

        $this->configureFcmMock();
        $this->artisan('babi:send-memory-notifications')->assertSuccessful();
        $this->assertDatabaseHas('memory_notification_deliveries', ['id' => $deliveryId, 'sent_at' => now()]);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'fcm.googleapis.com/v1/projects/babi-test-project/messages:send')
            && $request['message']['token'] === 'memory-notification-device-token-001'
            && $request['message']['data']['type'] === 'family_memory'
            && (int) $request['message']['data']['memory_id'] === $memoryId
            && ! str_contains($request['message']['notification']['body'], $this->childName()));

        $this->artisan('babi:send-memory-notifications')->assertSuccessful();
        Http::assertSentCount(1);
    }

    public function test_pending_memory_push_is_cancelled_when_membership_or_preference_is_revoked(): void
    {
        $viewer = User::factory()->create();
        $this->member($viewer);
        $this->addDevice($viewer, 'memory-notification-revoked-device-token');
        $formerMember = User::factory()->create();
        $this->member($formerMember);
        $this->addDevice($formerMember, 'memory-notification-former-member-device-token');
        $memoryId = $this->upload()->assertCreated()->json('data.id');
        $this->process($memoryId);
        $deliveryIds = DB::table('memory_notification_deliveries')->where('memory_id', $memoryId)
            ->pluck('id', 'user_id');
        $this->assertCount(2, $deliveryIds);

        DB::table('notification_preferences')->insert([
            'user_id' => $viewer->id, 'memory_enabled' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('family_members')->where('family_space_id', $this->space)->where('user_id', $formerMember->id)->delete();
        $this->configureFcmMock();
        $this->artisan('babi:send-memory-notifications')->assertSuccessful();
        foreach ($deliveryIds as $deliveryId) {
            $this->assertDatabaseHas('memory_notification_deliveries', ['id' => $deliveryId, 'cancelled_at' => now(), 'sent_at' => null]);
        }
        Http::assertNothingSent();
    }

    public function test_memory_notifications_remain_queued_when_firebase_is_not_configured(): void
    {
        $viewer = User::factory()->create();
        $this->member($viewer);
        $this->addDevice($viewer, 'memory-notification-no-config-device-token');
        $memoryId = $this->upload()->assertCreated()->json('data.id');
        $this->process($memoryId);
        $deliveryId = DB::table('memory_notification_deliveries')->where('memory_id', $memoryId)->value('id');
        config(['services.fcm.credentials' => null, 'services.fcm.project_id' => null]);

        $this->artisan('babi:send-memory-notifications')->assertSuccessful();

        $this->assertDatabaseHas('memory_notification_deliveries', [
            'id' => $deliveryId, 'sent_at' => null, 'failed_at' => null, 'cancelled_at' => null, 'claim_token' => null,
        ]);
        Http::assertNothingSent();
    }

    public function test_ambiguous_memory_push_result_is_not_retried(): void
    {
        $viewer = User::factory()->create();
        $this->member($viewer);
        $this->addDevice($viewer, 'memory-unknown-result-device-token');
        $memoryId = $this->upload()->assertCreated()->json('data.id');
        $this->process($memoryId);
        $deliveryId = DB::table('memory_notification_deliveries')->where('memory_id', $memoryId)->value('id');
        $fcm = \Mockery::mock(FcmClient::class);
        $fcm->shouldReceive('isConfigured')->twice()->andReturnTrue();
        $fcm->shouldReceive('send')->once()->andThrow(new FcmDeliveryUnknownException('ambiguous result'));
        $this->app->instance(FcmClient::class, $fcm);

        $this->artisan('babi:send-memory-notifications')->assertExitCode(1);
        $delivery = DB::table('memory_notification_deliveries')->where('id', $deliveryId)->first();
        $this->assertNotNull($delivery->unknown_at);
        $this->assertSame('provider_result_unknown', $delivery->reason_code);
        $this->assertSame(1, (int) $delivery->attempt_count);

        $this->artisan('babi:send-memory-notifications')->assertSuccessful();
        $this->assertSame(1, (int) DB::table('memory_notification_deliveries')->where('id', $deliveryId)->value('attempt_count'));
    }

    public function test_memory_push_temporary_failures_use_backoff_and_stop_after_three_attempts(): void
    {
        $viewer = User::factory()->create();
        $this->member($viewer);
        $this->addDevice($viewer, 'memory-retry-limit-device-token');
        $memoryId = $this->upload()->assertCreated()->json('data.id');
        $this->process($memoryId);
        $deliveryId = DB::table('memory_notification_deliveries')->where('memory_id', $memoryId)->value('id');
        $fcm = \Mockery::mock(FcmClient::class);
        $fcm->shouldReceive('isConfigured')->times(4)->andReturnTrue();
        $fcm->shouldReceive('send')->times(3)->andThrow(new FcmRetryableException('temporary failure'));
        $this->app->instance(FcmClient::class, $fcm);

        $this->artisan('babi:send-memory-notifications')->assertExitCode(1);
        $delivery = DB::table('memory_notification_deliveries')->where('id', $deliveryId)->first();
        $this->assertSame(1, (int) $delivery->attempt_count);
        $this->assertSame(now()->addMinutes(15)->toDateTimeString(), $delivery->retry_after);
        $this->travel(16)->minutes();
        $this->artisan('babi:send-memory-notifications')->assertExitCode(1);
        $delivery = DB::table('memory_notification_deliveries')->where('id', $deliveryId)->first();
        $this->assertSame(2, (int) $delivery->attempt_count);
        $this->assertSame(now()->addMinutes(30)->toDateTimeString(), $delivery->retry_after);
        $this->travel(31)->minutes();
        $this->artisan('babi:send-memory-notifications')->assertExitCode(1);
        $delivery = DB::table('memory_notification_deliveries')->where('id', $deliveryId)->first();
        $this->assertSame(3, (int) $delivery->attempt_count);
        $this->assertNotNull($delivery->failed_at);
        $this->assertSame('retry_limit_reached', $delivery->reason_code);
        $this->assertNull($delivery->retry_after);

        $this->travel(2)->hours();
        $this->artisan('babi:send-memory-notifications')->assertSuccessful();
        $this->assertSame(3, (int) DB::table('memory_notification_deliveries')->where('id', $deliveryId)->value('attempt_count'));
    }

    public function test_exhausted_stale_memory_push_claim_is_finalized_without_a_fourth_attempt(): void
    {
        $viewer = User::factory()->create();
        $this->member($viewer);
        $this->addDevice($viewer, 'memory-expired-claim-device-token');
        $memoryId = $this->upload()->assertCreated()->json('data.id');
        $this->process($memoryId);
        $deliveryId = DB::table('memory_notification_deliveries')->where('memory_id', $memoryId)->value('id');
        DB::table('memory_notification_deliveries')->where('id', $deliveryId)->update([
            'claim_token' => (string) Str::uuid(), 'claimed_at' => now()->subMinutes(6), 'attempt_count' => 3,
        ]);
        $fcm = \Mockery::mock(FcmClient::class);
        $fcm->shouldReceive('isConfigured')->once()->andReturnTrue();
        $fcm->shouldReceive('send')->never();
        $this->app->instance(FcmClient::class, $fcm);

        $this->artisan('babi:send-memory-notifications')->assertSuccessful();

        $this->assertDatabaseHas('memory_notification_deliveries', [
            'id' => $deliveryId, 'attempt_count' => 3, 'failed_at' => now(), 'reason_code' => 'retry_limit_reached',
            'claim_token' => null, 'claimed_at' => null, 'retry_after' => null,
        ]);
    }

    private function childName(): string
    {
        return ChildProfile::findOrFail($this->child)->name;
    }

    public function test_relative_uploads_private_memory_without_owner_health_permissions(): void
    {
        $viewer = User::factory()->create();
        $this->member($viewer);
        Sanctum::actingAs($viewer);
        $key = (string) Str::uuid();
        $id = $this->upload($key)->assertCreated()->json('data.id');
        $this->upload($key)->assertCreated()->assertJsonPath('data.id', $id);
        $this->getJson('/api/memories/'.$id)->assertOk()->assertJsonPath('data.status', 'uploading');
        $this->getJson('/api/child-profiles/'.$this->child.'/memories')->assertJsonCount(1, 'data.data');
        $other = User::factory()->create();
        $this->member($other);
        Sanctum::actingAs($other);
        $this->getJson('/api/memories/'.$id)->assertForbidden();
        $this->getJson('/api/child-profiles/'.$this->child.'/memories')->assertJsonCount(0, 'data.data');
        $this->process($id);
        Sanctum::actingAs($viewer);
        $this->getJson('/api/memories/'.$id)->assertOk()->assertJsonPath('data.status', 'published');
        $this->putJson('/api/memories/'.$id, ['caption' => 'Edit', 'expected_data_revision' => 2])->assertForbidden();
        $this->postJson('/api/health-records', ['child_profile_id' => $this->child, 'measured_at' => now()->toDateString(), 'height_cm' => 60, 'weight_kg' => 6, 'measurement_method' => 'lying', 'expected_data_revision' => 3])->assertForbidden();
    }

    public function test_family_members_can_comment_on_published_memories_and_owner_can_moderate(): void
    {
        $memoryId = $this->upload()->assertCreated()->json('data.id');
        $this->process($memoryId);
        $revision = ChildProfile::findOrFail($this->child)->data_revision;
        $ownerComment = $this->postJson('/api/memories/'.$memoryId.'/comments', ['content' => '  Thật đáng yêu!  '])
            ->assertCreated()->assertJsonPath('data.content', 'Thật đáng yêu!')->json('data.id');
        $this->assertSame($revision + 1, ChildProfile::findOrFail($this->child)->data_revision);

        $viewer = User::factory()->create();
        $this->member($viewer);
        Sanctum::actingAs($viewer);
        $viewerComment = $this->postJson('/api/memories/'.$memoryId.'/comments', ['content' => 'Bà nhớ khoảnh khắc này.'])
            ->assertCreated()->assertJsonPath('data.is_mine', true)->json('data.id');
        $this->getJson('/api/memories/'.$memoryId.'/comments')->assertOk()->assertJsonCount(2, 'data.data');
        $this->getJson('/api/memories/'.$memoryId)->assertJsonPath('data.comments', 2);
        $this->deleteJson('/api/comments/'.$ownerComment)->assertForbidden();
        $this->deleteJson('/api/comments/'.$viewerComment)->assertOk();

        Sanctum::actingAs($this->owner);
        $this->deleteJson('/api/comments/'.$ownerComment)->assertOk();
        $this->getJson('/api/memories/'.$memoryId)->assertJsonPath('data.comments', 0);
    }

    public function test_comments_require_published_memory_and_nonblank_text_of_at_most_500_characters(): void
    {
        $memoryId = $this->upload()->assertCreated()->json('data.id');
        $this->postJson('/api/memories/'.$memoryId.'/comments', ['content' => 'hello'])->assertStatus(409);
        $this->process($memoryId);
        $this->postJson('/api/memories/'.$memoryId.'/comments', ['content' => " \n\t "])->assertUnprocessable();
        $this->postJson('/api/memories/'.$memoryId.'/comments', ['content' => str_repeat('x', 501)])->assertUnprocessable();
        $this->postJson('/api/memories/'.$memoryId.'/comments', ['content' => str_repeat('x', 500)])->assertCreated();

        $other = User::factory()->create();
        Sanctum::actingAs($other);
        $this->getJson('/api/memories/'.$memoryId.'/comments')->assertForbidden();
    }

    public function test_upload_is_private_until_every_file_is_processed_and_image_is_resized(): void
    {
        $id = $this->upload()->assertCreated()->assertJsonPath('data.status', 'uploading')->assertJsonCount(0, 'data.media')->json('data.id');
        Queue::assertPushed(ProcessMemory::class, 1);
        $viewer = User::factory()->create();
        $this->member($viewer);
        Sanctum::actingAs($viewer);
        $this->getJson('/api/memories/'.$id)->assertForbidden();
        $this->getJson('/api/child-profiles/'.$this->child.'/memories')->assertJsonCount(0, 'data.data');
        $this->process($id);
        $response = $this->getJson('/api/memories/'.$id)->assertOk()->assertJsonPath('data.status', 'published');
        $this->assertStringNotContainsString('source_manifest', $response->getContent());
        $this->assertStringNotContainsString('file_path', $response->getContent());
        $file = DB::table('media_files')->first();
        $dimensions = getimagesizefromstring(Storage::disk('local')->get($file->file_path));
        $this->assertSame(1080, $dimensions[0]);
        $this->assertSame(540, $dimensions[1]);
        $sourcePath = json_decode(DB::table('memories')->find($id)->source_manifest, true)[0]['path'];
        $this->assertTrue(Storage::disk('local')->exists($sourcePath));
        $this->assertDatabaseCount('storage_cleanups', 1);
        $this->get('/api/media-files/'.$file->id)
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        DB::table('family_members')->where('user_id', $viewer->id)->delete();
        $this->get('/api/media-files/'.$file->id, ['Accept' => 'application/json'])->assertForbidden();
        $this->artisan('babi:reconcile-media')->assertExitCode(0);
        $this->assertFalse(Storage::disk('local')->exists($sourcePath));
        $this->assertDatabaseCount('storage_cleanups', 0);
    }

    public function test_multiple_image_and_video_files_publish_together_and_stream_video_privately(): void
    {
        $png = UploadedFile::fake()->image('photo.png', 32, 20);
        $webmBytes = hex2bin('1a45dfa39f4286810142f7810142f2810442f381084282847765626d4287810242858102');
        $webm = UploadedFile::fake()->createWithContent('recording.webm', $webmBytes);
        $id = $this->upload(null, ['files' => [$png, $webm]])
            ->assertCreated()
            ->assertJsonPath('data.status', 'uploading')
            ->assertJsonCount(0, 'data.media')
            ->json('data.id');

        $this->process($id);
        $memory = $this->getJson('/api/memories/'.$id)
            ->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonCount(2, 'data.media');
        $this->assertStringNotContainsString('file_path', $memory->getContent());
        $video = DB::table('media_files')->where('file_type', 'video')->first();
        $this->assertNotNull($video);
        $this->get('/api/media-files/'.$video->id)
            ->assertOk()
            ->assertHeader('Content-Type', 'video/webm')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame($webmBytes, $this->get('/api/media-files/'.$video->id)->streamedContent());
    }

    public function test_browser_webm_recording_is_accepted_and_kept_as_private_video(): void
    {
        $webm = UploadedFile::fake()->createWithContent('recording.webm', hex2bin('1a45dfa39f4286810142f7810142f2810442f381084282847765626d4287810242858102'));
        $id = $this->upload(null, ['files' => [$webm]])->assertCreated()->json('data.id');

        $this->process($id);
        $file = DB::table('media_files')->first();

        $this->assertSame('video', $file->file_type);
        $this->assertSame('video/webm', $file->mime_type);
        $this->assertStringEndsWith('.webm', $file->file_path);
        $this->assertSame($webm->getContent(), Storage::disk('local')->get($file->file_path));
    }

    public function test_mp4_recording_is_accepted_and_streamed_without_a_public_path(): void
    {
        $mp4Bytes = hex2bin('000000186674797069736f6d0000020069736f6d69736f32');
        $mp4 = UploadedFile::fake()->createWithContent('recording.mp4', $mp4Bytes)->mimeType('video/mp4');
        $id = $this->upload(null, ['files' => [$mp4]])->assertCreated()->json('data.id');

        $this->process($id);
        $file = DB::table('media_files')->first();
        $this->assertSame('video', $file->file_type);
        $this->assertSame('video/mp4', $file->mime_type);
        $this->assertStringEndsWith('.mp4', $file->file_path);

        $response = $this->get('/api/media-files/'.$file->id)
            ->assertOk()
            ->assertHeader('Content-Type', 'video/mp4')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame($mp4Bytes, $response->streamedContent());
    }

    public function test_jpeg_exif_orientation_is_applied_before_thumbnail_resize(): void
    {
        $image = imagecreatetruecolor(1800, 900);
        ob_start();
        imagejpeg($image, null, 90);
        $jpeg = ob_get_clean();
        imagedestroy($image);

        $tiff = 'II'.pack('vVv', 42, 8, 1)
            .pack('vvVv', 0x0112, 3, 1, 6)
            .pack('vV', 0, 0);
        $exif = "Exif\0\0".$tiff;
        $app1 = "\xFF\xE1".pack('n', strlen($exif) + 2).$exif;
        $orientedJpeg = substr($jpeg, 0, 2).$app1.substr($jpeg, 2);

        Storage::disk('local')->put('memory-sources/oriented.jpg', $orientedJpeg);
        $output = app(PrivateMedia::class)->process(['path' => 'memory-sources/oriented.jpg', 'mime' => 'image/jpeg']);
        $outputBytes = Storage::disk('local')->get($output['file_path']);
        $dimensions = getimagesizefromstring($outputBytes);

        $this->assertSame(540, $dimensions[0]);
        $this->assertSame(1080, $dimensions[1]);
        $this->assertStringNotContainsString("Exif\0\0", $outputBytes);
    }

    public function test_exif_transpose_and_transverse_orientations_keep_the_correct_corner_order(): void
    {
        $image = imagecreatetruecolor(400, 200);
        $red = imagecolorallocate($image, 255, 0, 0);
        $green = imagecolorallocate($image, 0, 255, 0);
        $blue = imagecolorallocate($image, 0, 0, 255);
        $yellow = imagecolorallocate($image, 255, 255, 0);
        imagefilledrectangle($image, 0, 0, 199, 99, $red);
        imagefilledrectangle($image, 200, 0, 399, 99, $green);
        imagefilledrectangle($image, 0, 100, 199, 199, $blue);
        imagefilledrectangle($image, 200, 100, 399, 199, $yellow);
        ob_start();
        imagejpeg($image, null, 100);
        $jpeg = ob_get_clean();
        imagedestroy($image);

        foreach ([
            5 => [$red, $blue, $green, $yellow],
            7 => [$yellow, $green, $blue, $red],
        ] as $orientation => $expectedCorners) {
            $tiff = 'II'.pack('vVv', 42, 8, 1)
                .pack('vvVv', 0x0112, 3, 1, $orientation)
                .pack('vV', 0, 0);
            $exif = "Exif\0\0".$tiff;
            $app1 = "\xFF\xE1".pack('n', strlen($exif) + 2).$exif;
            $orientedJpeg = substr($jpeg, 0, 2).$app1.substr($jpeg, 2);
            Storage::disk('local')->put("memory-sources/orientation-$orientation.jpg", $orientedJpeg);
            $output = app(PrivateMedia::class)->process(['path' => "memory-sources/orientation-$orientation.jpg", 'mime' => 'image/jpeg']);
            $decoded = imagecreatefromstring(Storage::disk('local')->get($output['file_path']));
            $points = [[50, 100], [150, 100], [50, 300], [150, 300]];

            foreach ($points as $index => [$x, $y]) {
                $actual = imagecolorat($decoded, $x, $y);
                $expected = $expectedCorners[$index];
                $distance = (($actual >> 16 & 0xFF) - ($expected >> 16 & 0xFF)) ** 2
                    + (($actual >> 8 & 0xFF) - ($expected >> 8 & 0xFF)) ** 2
                    + (($actual & 0xFF) - ($expected & 0xFF)) ** 2;
                $this->assertLessThan(40000, $distance, "Incorrect corner order for EXIF orientation $orientation at ($x, $y).");
            }

            imagedestroy($decoded);
        }
    }

    public function test_same_operation_and_content_never_create_duplicate_memories_or_source_files(): void
    {
        $key = (string) Str::uuid();
        $id = $this->upload($key)->assertCreated()->json('data.id');
        $this->upload($key)->assertCreated()->assertJsonPath('data.id', $id)->assertJsonPath('replayed', true);
        $this->upload($key, ['caption' => 'Different'])->assertConflict();
        $this->assertDatabaseCount('memories', 1);
        $this->assertCount(1, Storage::disk('local')->allFiles('memory-sources'));
        $this->getJson('/api/operations/'.$key)->assertOk()->assertJsonPath('data.resource_id', $id);
    }

    public function test_job_rechecks_consent_and_creator_membership_before_publishing(): void
    {
        $id = $this->upload()->assertCreated()->json('data.id');
        DB::table('child_data_consents')->where('child_profile_id', $this->child)->update(['withdrawn_at' => now()]);
        try {
            $this->process($id);
            $this->fail('Expected access denial');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertDatabaseCount('media_files', 0);
        DB::table('child_data_consents')->where('child_profile_id', $this->child)->update(['withdrawn_at' => null]);
        DB::table('family_members')->where('user_id', $this->owner->id)->delete();
        try {
            $this->process($id);
            $this->fail('Expected access denial');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertDatabaseCount('media_files', 0);
    }

    public function test_job_failure_is_retryable_on_same_id_and_stale_generation_cannot_publish(): void
    {
        $id = $this->upload()->assertCreated()->json('data.id');
        (new ProcessMemory($id, 1))->failed(new \RuntimeException('TEST'));
        $this->postJson('/api/memories/'.$id.'/retry')->assertOk()->assertJsonPath('data.status', 'uploading');
        $this->process($id);
        $this->assertDatabaseCount('media_files', 0);
        (new ProcessMemory($id, 2))->handle(app(FamilyAccess::class), app(PrivateMedia::class));
        $this->assertDatabaseCount('memories', 1);
        $this->assertDatabaseCount('media_files', 1);
        $this->postJson('/api/memories/'.$id.'/retry')->assertConflict();
    }

    public function test_media_processing_claim_skips_overlap_and_reconcile_reenqueues_once_after_stale_lease(): void
    {
        $id = $this->upload()->assertCreated()->json('data.id');
        $activeWorker = new ProcessMemory($id, 1);
        DB::table('memories')->where('id', $id)->update([
            'processing_claim_token' => $activeWorker->claimToken,
            'processing_claimed_at' => now(),
            'updated_at' => now(),
        ]);

        (new ProcessMemory($id, 1))->handle(app(FamilyAccess::class), app(PrivateMedia::class));
        $this->assertDatabaseHas('memories', ['id' => $id, 'status' => 'uploading', 'processing_claim_token' => $activeWorker->claimToken]);
        $this->assertDatabaseCount('media_files', 0);

        DB::table('memories')->where('id', $id)->update([
            'processing_claimed_at' => now()->subMinutes(3),
            'updated_at' => now()->subMinutes(6),
        ]);
        $this->artisan('babi:reconcile-media')->assertSuccessful();
        $this->artisan('babi:reconcile-media')->assertSuccessful();
        Queue::assertPushed(ProcessMemory::class, 2);

        $this->process($id);
        $this->assertDatabaseHas('memories', ['id' => $id, 'status' => 'published', 'processing_claim_token' => null, 'processing_claimed_at' => null]);
        $this->assertDatabaseCount('media_files', 1);
    }

    public function test_reactions_toggle_and_owner_deletion_durably_cleans_sources_and_outputs(): void
    {
        $id = $this->upload()->assertCreated()->json('data.id');
        $this->process($id);
        $this->postJson('/api/memories/'.$id.'/reactions', ['reaction_type' => 'love'])->assertOk()->assertJsonPath('data.likes', 1);
        $this->postJson('/api/memories/'.$id.'/reactions', ['reaction_type' => 'wow'])->assertOk()->assertJsonPath('data.likes', 1);
        $this->postJson('/api/memories/'.$id.'/reactions', ['reaction_type' => 'wow'])->assertOk()->assertJsonPath('data.likes', 0);
        $revision = ChildProfile::findOrFail($this->child)->data_revision;
        $this->deleteJson('/api/memories/'.$id, ['confirmed' => true, 'expected_data_revision' => $revision])->assertStatus(202);
        $this->assertDatabaseCount('memories', 0);
        $this->assertDatabaseCount('media_files', 0);
        $this->assertDatabaseCount('storage_cleanups', 2);
        $this->artisan('babi:reconcile-media')->assertSuccessful();
        $this->assertCount(0, Storage::disk('local')->allFiles());
        $this->assertDatabaseCount('storage_cleanups', 0);
    }

    public function test_validation_rejects_bad_dates_empty_caption_and_oversized_images(): void
    {
        $this->upload(null, ['caption' => '   '])->assertUnprocessable();
        $this->upload(null, ['memory_date' => now(config('babi.calendar_timezone'))->addDay()->toDateString()])->assertUnprocessable();
        $this->upload(null, ['files' => [UploadedFile::fake()->image('large.png')->size(10241)]])->assertUnprocessable();
        $largeVideo = UploadedFile::fake()->create('large.mp4')->size(51201)->mimeType('video/mp4');
        $this->upload(null, ['files' => [$largeVideo]])->assertUnprocessable();
        $tooManyFiles = array_fill(0, 11, UploadedFile::fake()->image('photo.png'));
        $this->upload(null, ['files' => $tooManyFiles])->assertUnprocessable();
        $this->assertDatabaseCount('memories', 0);
    }

    public function test_birth_date_edit_cannot_move_after_existing_memory(): void
    {
        $this->upload(null, ['memory_date' => now()->subDay()->toDateString()])->assertCreated();
        $this->putJson('/api/child-profiles/'.$this->child, ['name' => 'Test child', 'gender' => 'female', 'date_of_birth' => now()->toDateString(), 'expected_data_revision' => 2])->assertConflict();
    }

    public function test_partial_processing_failure_never_publishes_or_keeps_untracked_outputs(): void
    {
        $id = $this->upload(null, ['files' => [UploadedFile::fake()->image('a.png'), UploadedFile::fake()->image('b.png')]])->assertCreated()->json('data.id');
        $memory = DB::table('memories')->find($id);
        $sources = json_decode($memory->source_manifest, true);
        Storage::disk('local')->delete($sources[1]['path']);
        try {
            $this->process($id);
            $this->fail('Expected missing source to fail the complete batch');
        } catch (\RuntimeException $e) {
            $this->assertSame('SOURCE_READ_FAILED', $e->getMessage());
            $this->assertDatabaseCount('media_files', 0);
        }
        $this->assertDatabaseHas('memories', ['id' => $id, 'status' => 'uploading']);
        $this->assertDatabaseCount('storage_cleanups', 1);
        $this->artisan('babi:reconcile-media')->assertSuccessful();
        $this->assertCount(0, Storage::disk('local')->allFiles('media'));
        $this->assertTrue(Storage::disk('local')->exists($sources[0]['path']));
    }
}
