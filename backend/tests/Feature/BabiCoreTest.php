<?php

namespace Tests\Feature;

use App\Models\ChildProfile;
use App\Models\User;
use App\Notifications\ResetPasswordWithBabiLink;
use App\Notifications\VerifyEmailWithFamilyLink;
use App\Services\FcmClient;
use App\Services\FcmDeliveryUnknownException;
use App\Services\FcmRetryableException;
use App\Services\VaccineCatalogImporter;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BabiCoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 17)->startOfDay());
        config(['babi.vaccine_catalog_version' => 'TEST-ONLY']);
        Mail::fake();
        // Synthetic fixture, deliberately absent from database seeders.
        foreach ([['TEST-AGE', 'age', 1, 'month'], ['TEST-CLINICAL', 'clinical', null, null]] as [$code, $rule, $age, $unit]) {
            DB::table('vaccine_templates')->insert(['vaccine_code' => $code, 'name' => 'Synthetic test fixture', 'dose_number' => 1, 'disease' => 'TEST ONLY', 'program' => 'expanded', 'schedule_version' => 'TEST-ONLY', 'timing_rule' => $rule, 'age_value' => $age, 'age_unit' => $unit, 'source_url' => 'https://example.invalid/test-only', 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function owner(): User
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    private function payload(): array
    {
        return ['name' => 'Bé An', 'date_of_birth' => '2026-01-31', 'gender' => 'female', 'representative_confirmed' => true, 'storage_consent' => true, 'family_sharing_acknowledged' => true, 'policy_version' => config('babi.policy_version')];
    }

    private function child(): int
    {
        return $this->postJson('/api/child-profiles', $this->payload(), ['X-Operation-Key' => (string) Str::uuid()])->assertCreated()->json('data.id');
    }

    private function member(int $space, User $user, string $role = 'viewer'): int
    {
        return DB::table('family_members')->insertGetId(['family_space_id' => $space, 'user_id' => $user->id, 'role' => $role, 'joined_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function configureFcmMockForTest(string $credentialPrefix): void
    {
        $clientEmail = $credentialPrefix.'-'.Str::uuid().'@babi.invalid';
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

    public function test_unauthenticated_api_request_returns_json_without_web_login_redirect(): void
    {
        $this->get('/api/child-profiles')
            ->assertUnauthorized()
            ->assertHeader('content-type', 'application/json')
            ->assertJsonPath('success', false);
    }

    public function test_api_validation_messages_use_vietnamese_without_changing_web_locale(): void
    {
        $this->postJson('/api/auth/register', [])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Trường tên là bắt buộc. (còn 2 lỗi khác.)')
            ->assertJsonPath('errors.name.0', 'Trường tên là bắt buộc.');

        $this->assertSame('en', app()->getLocale());
    }

    public function test_mysql_event_and_expiry_timestamps_are_not_implicit_on_update(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('MariaDB/MySQL TIMESTAMP metadata only.');
        }

        $unexpected = DB::select(
            "select table_name, column_name from information_schema.columns where table_schema = database() and extra like '%on update current_timestamp%' and ((table_name = 'child_data_consents' and column_name = 'granted_at') or (table_name = 'device_tokens' and column_name = 'last_used_at') or (table_name = 'family_invitations' and column_name = 'expires_at') or (table_name = 'family_join_requests' and column_name = 'requested_at') or (table_name = 'family_members' and column_name = 'joined_at') or (table_name = 'family_space_links' and column_name = 'expires_at') or (table_name = 'operation_requests' and column_name = 'expires_at'))"
        );

        $this->assertSame([], $unexpected);
    }

    public function test_password_reset_changes_password_once_and_revokes_existing_sessions(): void
    {
        $user = User::factory()->create(['password' => 'old-password-123']);
        $session = $user->createToken('existing-session');
        $token = app('auth.password.broker')->createToken($user);

        $this->postJson('/api/auth/password/reset', [
            'email' => $user->email, 'token' => $token,
            'password' => 'new-password-456', 'password_confirmation' => 'new-password-456',
        ])->assertOk();

        $this->assertTrue(Hash::check('new-password-456', $user->fresh()->password));
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $session->accessToken->id]);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        $this->postJson('/api/auth/password/reset', [
            'email' => $user->email, 'token' => $token,
            'password' => 'other-password-789', 'password_confirmation' => 'other-password-789',
        ])->assertUnprocessable()->assertJsonPath('message', 'Mã đặt lại mật khẩu không hợp lệ hoặc đã hết hạn.');
        $this->assertTrue(Hash::check('new-password-456', $user->fresh()->password));
    }

    public function test_password_reset_request_does_not_disclose_unknown_email(): void
    {
        $known = User::factory()->create();
        $unknown = 'missing-account@example.test';
        $knownResponse = $this->postJson('/api/auth/password/forgot', ['email' => $known->email]);
        $unknownResponse = $this->postJson('/api/auth/password/forgot', ['email' => $unknown]);
        $knownResponse->assertOk();
        $unknownResponse->assertOk();
        $this->assertSame($knownResponse->json('message'), $unknownResponse->json('message'));
    }

    public function test_password_reset_mail_links_to_flutter_with_encoded_account_email(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'reset+family@example.test']);
        $this->postJson('/api/auth/password/forgot', ['email' => $user->email])->assertOk();

        Notification::assertSentTo($user, ResetPasswordWithBabiLink::class, function ($notification) use ($user) {
            $mail = $notification->toMail($user);

            return str_starts_with($mail->actionUrl, 'http://127.0.0.1:5173/?passwordReset=')
                && str_contains($mail->actionUrl, '&email=reset%2Bfamily%40example.test');
        });
    }

    public function test_owner_configures_reminder_days_and_emails_and_delivery_is_idempotent(): void
    {
        $owner = $this->owner();
        $childId = $this->child();
        $spaceId = ChildProfile::findOrFail($childId)->family_space_id;
        $viewer = User::factory()->create();
        $this->member($spaceId, $viewer);
        $schedules = DB::table('vaccination_schedules')->where('child_profile_id', $childId)->orderBy('id')->take(2)->get();
        $this->assertCount(2, $schedules);
        $extraEmail = 'grandparent@example.test';
        $this->getJson('/api/family-spaces/'.$spaceId.'/vaccination-reminder-settings')
            ->assertOk()->assertJsonPath('data.days_before', [7]);
        $this->putJson('/api/family-spaces/'.$spaceId.'/vaccination-reminder-settings', [
            'days_before' => [5], 'recipient_emails' => [$extraEmail], 'send_time' => '08:00', 'start_date' => '2026-10-17',
        ])->assertOk()->assertJsonPath('data.days_before.0', 5)->assertJsonPath('data.recipient_emails.0', $extraEmail);
        DB::table('vaccination_schedules')->where('id', $schedules[0]->id)->update(['appointment_date' => '2026-10-22']);
        DB::table('vaccination_schedules')->where('id', $schedules[1]->id)->update(['due_date' => '2026-10-22']);

        $bodies = [];
        Mail::shouldReceive('raw')->times(6)->withArgs(function ($body, $callback) use (&$bodies) {
            $bodies[] = $body;

            return str_contains($body, 'Bé An') && is_callable($callback);
        })->andReturnNull();
        Artisan::call('babi:send-vaccination-reminders', ['--date' => '2026-10-17', '--time' => '07:59']);
        Artisan::call('babi:send-vaccination-reminders', ['--date' => '2026-10-17', '--time' => '08:00']);
        Artisan::call('babi:send-vaccination-reminders', ['--date' => '2026-10-17', '--time' => '08:05']);
        Artisan::call('babi:send-vaccination-reminders', ['--date' => '2026-10-17', '--time' => '08:10']);

        $deliveries = DB::table('vaccination_reminder_deliveries')->where('vaccination_schedule_id', $schedules[0]->id)->get();
        $this->assertCount(2, $deliveries);
        $referenceDateDeliveries = DB::table('vaccination_reminder_deliveries')->where('vaccination_schedule_id', $schedules[1]->id)->get();
        $this->assertCount(2, $referenceDateDeliveries);
        $this->assertTrue(collect($bodies)->contains(fn ($body) => str_contains($body, 'ngày hẹn từ cơ sở tiêm')));
        $this->assertTrue(collect($bodies)->contains(fn ($body) => str_contains($body, 'ngày tham khảo')));
        $this->assertEqualsCanonicalizing([$owner->email, $extraEmail], $deliveries->pluck('email')->all());
        $this->assertTrue($deliveries->every(fn ($delivery) => $delivery->sent_at !== null));
        $this->assertTrue($referenceDateDeliveries->every(fn ($delivery) => $delivery->sent_at !== null));
        DB::table('vaccination_schedules')->where('id', $schedules[0]->id)->update(['appointment_date' => '2026-10-27']);
        Artisan::call('babi:send-vaccination-reminders', ['--date' => '2026-10-22', '--time' => '08:00']);
        $rescheduledDeliveries = DB::table('vaccination_reminder_deliveries')->where('vaccination_schedule_id', $schedules[0]->id)->get();
        $this->assertCount(4, $rescheduledDeliveries);
        $this->assertCount(2, $rescheduledDeliveries->where('reminder_date', '2026-10-22'));
        $this->assertCount(2, $rescheduledDeliveries->where('reminder_date', '2026-10-27'));
        Sanctum::actingAs($viewer);
        $this->getJson('/api/family-spaces/'.$spaceId.'/vaccination-reminder-settings')->assertForbidden();
        $this->putJson('/api/family-spaces/'.$spaceId.'/vaccination-reminder-settings', [
            'days_before' => [5], 'recipient_emails' => ['not-an-email'],
        ])->assertForbidden();
    }

    public function test_only_owner_can_review_scoped_vaccination_email_delivery_status(): void
    {
        $owner = $this->owner();
        $childId = $this->child();
        $child = ChildProfile::findOrFail($childId);
        $scheduleId = DB::table('vaccination_schedules')->where('child_profile_id', $childId)->value('id');
        DB::table('vaccination_reminder_deliveries')->insert([
            [
                'vaccination_schedule_id' => $scheduleId,
                'reminder_date' => '2026-10-24',
                'days_before' => 7,
                'email' => 'recipient@example.test',
                'attempt_count' => 1,
                'retry_after' => null,
                'failed_at' => null,
                'reason_code' => null,
                'sent_at' => now(),
                'created_at' => now()->subMinute(),
                'updated_at' => now(),
            ],
            [
                'vaccination_schedule_id' => $scheduleId,
                'reminder_date' => '2026-10-24',
                'days_before' => 3,
                'email' => 'recipient@example.test',
                'attempt_count' => 3,
                'retry_after' => null,
                'failed_at' => now(),
                'reason_code' => 'retry_limit_reached',
                'sent_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        $spaceId = $child->family_space_id;
        $otherOwner = User::factory()->create();
        Sanctum::actingAs($otherOwner);
        $otherChildId = $this->child();
        $otherScheduleId = DB::table('vaccination_schedules')->where('child_profile_id', $otherChildId)->value('id');
        DB::table('vaccination_reminder_deliveries')->insert([
            'vaccination_schedule_id' => $otherScheduleId,
            'reminder_date' => '2026-10-24',
            'days_before' => 7,
            'email' => 'private-other-family@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($owner);
        $this->getJson('/api/family-spaces/'.$spaceId.'/vaccination-reminder-deliveries?per_page=10')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.data.0.delivery_status', 'needs_attention')
            ->assertJsonPath('data.data.0.reason_code', 'retry_limit_reached')
            ->assertJsonPath('data.data.0.attempt_count', 3)
            ->assertJsonPath('data.data.0.child_name', 'Bé An')
            ->assertJsonPath('data.data.0.vaccine_name', 'Synthetic test fixture')
            ->assertJsonPath('data.data.1.delivery_status', 'accepted_by_mail_transport')
            ->assertJsonPath('data.data.1.email', 'recipient@example.test');

        $viewer = User::factory()->create();
        $this->member($spaceId, $viewer);
        Sanctum::actingAs($viewer);
        $this->getJson('/api/family-spaces/'.$spaceId.'/vaccination-reminder-deliveries')->assertForbidden();
    }

    public function test_vaccination_reminder_does_not_send_after_storage_consent_is_withdrawn(): void
    {
        $owner = $this->owner();
        $childId = $this->child();
        $spaceId = ChildProfile::findOrFail($childId)->family_space_id;
        $scheduleId = DB::table('vaccination_schedules')->where('child_profile_id', $childId)->value('id');
        DB::table('vaccination_schedules')->where('id', $scheduleId)->update(['appointment_date' => '2026-10-22']);
        DB::table('vaccination_reminder_settings')->insert([
            'family_space_id' => $spaceId, 'days_before' => json_encode([5]),
            'recipient_emails' => json_encode([]), 'send_time' => '08:00', 'start_date' => '2026-10-17',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('child_data_consents')->where('child_profile_id', $childId)->where('purpose', 'storage')
            ->update(['withdrawn_at' => now(), 'updated_at' => now()]);

        Mail::shouldReceive('raw')->never();
        Artisan::call('babi:send-vaccination-reminders', ['--date' => '2026-10-17', '--time' => '08:00']);

        $this->assertDatabaseCount('vaccination_reminder_deliveries', 0);
    }

    public function test_vaccination_reminder_skips_unverified_owner_email_but_keeps_owner_push_and_explicit_extra_email(): void
    {
        $owner = $this->owner();
        $owner->forceFill(['email_verified_at' => null])->save();
        $childId = $this->child();
        $child = ChildProfile::findOrFail($childId);
        $scheduleId = DB::table('vaccination_schedules')->where('child_profile_id', $childId)->value('id');
        DB::table('vaccination_schedules')->where('id', $scheduleId)->update(['appointment_date' => '2026-10-22']);
        $extraEmail = 'explicit-recipient@example.test';
        DB::table('vaccination_reminder_settings')->insert([
            'family_space_id' => $child->family_space_id, 'days_before' => json_encode([5]),
            'recipient_emails' => json_encode([$extraEmail]), 'send_time' => '08:00', 'start_date' => '2026-10-17',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('device_tokens')->insert([
            'user_id' => $owner->id, 'fcm_token' => Crypt::encryptString('unverified-owner-device-token'),
            'token_hash' => hash('sha256', 'unverified-owner-device-token'), 'device_type' => 'android',
            'installation_id' => (string) Str::uuid(), 'last_used_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        Mail::shouldReceive('raw')->once();
        Artisan::call('babi:send-vaccination-reminders', ['--date' => '2026-10-17', '--time' => '08:00']);

        $this->assertDatabaseHas('vaccination_reminder_deliveries', [
            'vaccination_schedule_id' => $scheduleId, 'email' => $extraEmail,
        ]);
        $this->assertDatabaseMissing('vaccination_reminder_deliveries', [
            'vaccination_schedule_id' => $scheduleId, 'email' => $owner->email,
        ]);
        $this->assertDatabaseHas('vaccination_push_deliveries', [
            'vaccination_schedule_id' => $scheduleId, 'user_id' => $owner->id,
        ]);
    }

    public function test_editing_reminder_settings_preserves_creation_timestamp(): void
    {
        $this->owner();
        $childId = $this->child();
        $spaceId = ChildProfile::findOrFail($childId)->family_space_id;
        $endpoint = '/api/family-spaces/'.$spaceId.'/vaccination-reminder-settings';
        $payload = [
            'days_before' => [7],
            'recipient_emails' => [],
            'send_time' => '08:00',
            'start_date' => '2026-10-17',
        ];

        $this->putJson($endpoint, $payload)->assertOk();
        $createdAt = DB::table('vaccination_reminder_settings')->where('family_space_id', $spaceId)->value('created_at');
        $updatedAt = DB::table('vaccination_reminder_settings')->where('family_space_id', $spaceId)->value('updated_at');

        $this->travel(5)->minutes();
        $payload['days_before'] = [3, 7];
        $this->putJson($endpoint, $payload)->assertOk()->assertJsonPath('data.days_before', [3, 7]);

        $settings = DB::table('vaccination_reminder_settings')->where('family_space_id', $spaceId)->first();
        $this->assertSame($createdAt, $settings->created_at);
        $this->assertNotSame($updatedAt, $settings->updated_at);
    }

    public function test_vaccination_push_reminder_uses_owner_device_and_configured_offset_once(): void
    {
        $owner = $this->owner();
        $childId = $this->child();
        $child = ChildProfile::findOrFail($childId);
        $schedule = DB::table('vaccination_schedules')->where('child_profile_id', $childId)->orderBy('id')->first();
        DB::table('vaccination_schedules')->where('id', $schedule->id)->update(['appointment_date' => '2026-10-24']);
        $viewer = User::factory()->create();
        $this->member($child->family_space_id, $viewer);
        foreach ([[$owner, 'vaccine-push-owner-test-device-token'], [$viewer, 'vaccine-push-viewer-test-device-token']] as [$user, $token]) {
            DB::table('device_tokens')->insert([
                'user_id' => $user->id, 'fcm_token' => Crypt::encryptString($token),
                'token_hash' => hash('sha256', $token), 'device_type' => 'android', 'installation_id' => (string) Str::uuid(),
                'last_used_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::table('vaccination_reminder_settings')->insert([
            'family_space_id' => $child->family_space_id, 'days_before' => json_encode([7]),
            'recipient_emails' => json_encode([]), 'send_time' => '08:00', 'start_date' => '2026-10-17',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Artisan::call('babi:send-vaccination-reminders', ['--date' => '2026-10-17', '--time' => '08:00']);
        $delivery = DB::table('vaccination_push_deliveries')->where('vaccination_schedule_id', $schedule->id)->first();
        $this->assertNotNull($delivery);
        $this->assertSame($owner->id, (int) $delivery->user_id);
        $this->assertSame('2026-10-24', $delivery->reminder_date);
        $this->assertSame(7, (int) $delivery->days_before);
        $this->assertDatabaseMissing('vaccination_push_deliveries', ['user_id' => $viewer->id]);

        $this->travelTo(now()->setTime(8, 0));
        $this->configureFcmMockForTest('vaccine-push');
        $this->artisan('babi:send-vaccination-push-reminders')->assertSuccessful();
        $this->assertDatabaseHas('vaccination_push_deliveries', ['id' => $delivery->id, 'sent_at' => now()]);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'fcm.googleapis.com/v1/projects/babi-test-project/messages:send')
            && $request['message']['token'] === 'vaccine-push-owner-test-device-token'
            && $request['message']['data']['type'] === 'vaccination_reminder'
            && (int) $request['message']['data']['vaccination_schedule_id'] === $schedule->id);

        $this->artisan('babi:send-vaccination-push-reminders')->assertSuccessful();
        Http::assertSentCount(1);
    }

    public function test_vaccination_push_cancels_when_owner_disables_preference_or_consent(): void
    {
        $owner = $this->owner();
        $childId = $this->child();
        $child = ChildProfile::findOrFail($childId);
        $scheduleId = DB::table('vaccination_schedules')->where('child_profile_id', $childId)->value('id');
        DB::table('vaccination_schedules')->where('id', $scheduleId)->update(['appointment_date' => '2026-10-24']);
        $token = 'vaccine-push-revoked-test-device-token';
        DB::table('device_tokens')->insert([
            'user_id' => $owner->id, 'fcm_token' => Crypt::encryptString($token),
            'token_hash' => hash('sha256', $token), 'device_type' => 'ios', 'installation_id' => (string) Str::uuid(),
            'last_used_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('vaccination_reminder_settings')->insert([
            'family_space_id' => $child->family_space_id, 'days_before' => json_encode([7]),
            'recipient_emails' => json_encode([]), 'send_time' => '08:00', 'start_date' => '2026-10-17',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Artisan::call('babi:send-vaccination-reminders', ['--date' => '2026-10-17', '--time' => '08:00']);
        $deliveryId = DB::table('vaccination_push_deliveries')->where('vaccination_schedule_id', $scheduleId)->value('id');
        $this->assertNotNull($deliveryId);
        DB::table('notification_preferences')->insert([
            'user_id' => $owner->id, 'vaccination_enabled' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('child_data_consents')->where('child_profile_id', $childId)->where('purpose', 'storage')->update(['withdrawn_at' => now()]);

        $this->travelTo(now()->setTime(8, 0));
        $this->configureFcmMockForTest('vaccine-push-disabled');
        $this->artisan('babi:send-vaccination-push-reminders')->assertSuccessful();
        $this->assertDatabaseHas('vaccination_push_deliveries', ['id' => $deliveryId, 'cancelled_at' => now(), 'sent_at' => null]);
        Http::assertNothingSent();
    }

    public function test_vaccination_push_temporary_failures_backoff_and_stop_after_three_attempts(): void
    {
        $owner = $this->owner();
        $childId = $this->child();
        $child = ChildProfile::findOrFail($childId);
        $scheduleId = DB::table('vaccination_schedules')->where('child_profile_id', $childId)->value('id');
        DB::table('vaccination_schedules')->where('id', $scheduleId)->update(['appointment_date' => '2026-10-24']);
        $token = 'vaccine-push-retry-limit-test-token';
        DB::table('device_tokens')->insert([
            'user_id' => $owner->id, 'fcm_token' => Crypt::encryptString($token),
            'token_hash' => hash('sha256', $token), 'device_type' => 'android', 'installation_id' => (string) Str::uuid(),
            'last_used_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('vaccination_reminder_settings')->insert([
            'family_space_id' => $child->family_space_id, 'days_before' => json_encode([7]), 'recipient_emails' => json_encode([]),
            'send_time' => '08:00', 'start_date' => '2026-10-17', 'created_at' => now(), 'updated_at' => now(),
        ]);
        Artisan::call('babi:send-vaccination-reminders', ['--date' => '2026-10-17', '--time' => '08:00']);
        $deliveryId = DB::table('vaccination_push_deliveries')->where('vaccination_schedule_id', $scheduleId)->value('id');
        $this->assertNotNull($deliveryId);
        $this->travelTo(now()->setTime(8, 0));

        $fcm = \Mockery::mock(FcmClient::class);
        $fcm->shouldReceive('isConfigured')->times(4)->andReturnTrue();
        $fcm->shouldReceive('send')->times(3)->andThrow(new FcmRetryableException('temporary failure'));
        $this->app->instance(FcmClient::class, $fcm);

        $this->artisan('babi:send-vaccination-push-reminders')->assertExitCode(1);
        $delivery = DB::table('vaccination_push_deliveries')->where('id', $deliveryId)->first();
        $this->assertSame(1, (int) $delivery->attempt_count);
        $this->assertSame(now()->addMinutes(15)->toDateTimeString(), $delivery->retry_after);
        $this->travel(16)->minutes();
        $this->artisan('babi:send-vaccination-push-reminders')->assertExitCode(1);
        $delivery = DB::table('vaccination_push_deliveries')->where('id', $deliveryId)->first();
        $this->assertSame(2, (int) $delivery->attempt_count);
        $this->assertSame(now()->addMinutes(30)->toDateTimeString(), $delivery->retry_after);
        $this->travel(31)->minutes();
        $this->artisan('babi:send-vaccination-push-reminders')->assertExitCode(1);
        $delivery = DB::table('vaccination_push_deliveries')->where('id', $deliveryId)->first();
        $this->assertSame(3, (int) $delivery->attempt_count);
        $this->assertNotNull($delivery->failed_at);
        $this->assertSame('retry_limit_reached', $delivery->reason_code);
        $this->assertNull($delivery->retry_after);

        $this->travel(2)->hours();
        $this->artisan('babi:send-vaccination-push-reminders')->assertSuccessful();
        $this->assertSame(3, (int) DB::table('vaccination_push_deliveries')->where('id', $deliveryId)->value('attempt_count'));
    }

    public function test_exhausted_stale_vaccination_push_claim_is_finalized_without_a_fourth_attempt(): void
    {
        $owner = $this->owner();
        $childId = $this->child();
        $scheduleId = DB::table('vaccination_schedules')->where('child_profile_id', $childId)->value('id');
        $deliveryId = DB::table('vaccination_push_deliveries')->insertGetId([
            'vaccination_schedule_id' => $scheduleId, 'reminder_date' => '2026-10-24', 'days_before' => 7,
            'user_id' => $owner->id, 'device_token_id' => null, 'claim_token' => (string) Str::uuid(),
            'claimed_at' => now()->subMinutes(6), 'attempt_count' => 3, 'retry_after' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $fcm = \Mockery::mock(FcmClient::class);
        $fcm->shouldReceive('isConfigured')->once()->andReturnTrue();
        $fcm->shouldReceive('send')->never();
        $this->app->instance(FcmClient::class, $fcm);

        $this->artisan('babi:send-vaccination-push-reminders')->assertSuccessful();

        $this->assertDatabaseHas('vaccination_push_deliveries', [
            'id' => $deliveryId, 'attempt_count' => 3, 'failed_at' => now(), 'reason_code' => 'retry_limit_reached',
            'claim_token' => null, 'claimed_at' => null, 'retry_after' => null,
        ]);
    }

    public function test_vaccination_push_with_unknown_provider_result_is_not_retried(): void
    {
        $owner = $this->owner();
        $childId = $this->child();
        $child = ChildProfile::findOrFail($childId);
        $scheduleId = DB::table('vaccination_schedules')->where('child_profile_id', $childId)->value('id');
        DB::table('vaccination_schedules')->where('id', $scheduleId)->update(['appointment_date' => '2026-10-24']);
        $token = 'vaccine-push-unknown-result-test-token';
        DB::table('device_tokens')->insert([
            'user_id' => $owner->id, 'fcm_token' => Crypt::encryptString($token),
            'token_hash' => hash('sha256', $token), 'device_type' => 'ios', 'installation_id' => (string) Str::uuid(),
            'last_used_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('vaccination_reminder_settings')->insert([
            'family_space_id' => $child->family_space_id, 'days_before' => json_encode([7]), 'recipient_emails' => json_encode([]),
            'send_time' => '08:00', 'start_date' => '2026-10-17', 'created_at' => now(), 'updated_at' => now(),
        ]);
        Artisan::call('babi:send-vaccination-reminders', ['--date' => '2026-10-17', '--time' => '08:00']);
        $deliveryId = DB::table('vaccination_push_deliveries')->where('vaccination_schedule_id', $scheduleId)->value('id');
        $this->assertNotNull($deliveryId);
        $this->travelTo(now()->setTime(8, 0));

        $fcm = \Mockery::mock(FcmClient::class);
        $fcm->shouldReceive('isConfigured')->twice()->andReturnTrue();
        $fcm->shouldReceive('send')->once()->andThrow(new FcmDeliveryUnknownException('ambiguous result'));
        $this->app->instance(FcmClient::class, $fcm);

        $this->artisan('babi:send-vaccination-push-reminders')->assertExitCode(1);
        $delivery = DB::table('vaccination_push_deliveries')->where('id', $deliveryId)->first();
        $this->assertNotNull($delivery->unknown_at);
        $this->assertSame('provider_result_unknown', $delivery->reason_code);
        $this->assertSame(1, (int) $delivery->attempt_count);
        $this->assertNull($delivery->claim_token);

        $this->artisan('babi:send-vaccination-push-reminders')->assertSuccessful();
        $this->assertSame(1, (int) DB::table('vaccination_push_deliveries')->where('id', $deliveryId)->value('attempt_count'));
    }

    public function test_appointment_reminder_is_owner_managed_one_time_and_completed_items_cannot_reactivate(): void
    {
        $owner = $this->owner();
        $childId = $this->child();
        $child = ChildProfile::findOrFail($childId);
        $viewer = User::factory()->create();
        $this->member($child->family_space_id, $viewer);

        $payload = [
            'title' => 'Khám định kỳ', 'type' => 'doctor_visit',
            'remind_at' => '2026-10-18T09:30:00+07:00', 'location' => 'Trạm y tế', 'notes' => 'Mang sổ khám',
            'expected_data_revision' => $child->data_revision,
        ];
        $this->postJson('/api/child-profiles/'.$childId.'/appointment-reminders', array_replace($payload, ['expected_data_revision' => $child->data_revision - 1]))
            ->assertConflict();
        $this->assertDatabaseCount('appointment_reminders', 0);
        $id = $this->postJson('/api/child-profiles/'.$childId.'/appointment-reminders', $payload)
            ->assertCreated()->assertJsonPath('data.remind_at', '2026-10-18T09:30:00+07:00')->json('data.id');
        $this->assertDatabaseHas('appointment_reminders', ['id' => $id, 'created_by' => $owner->id, 'remind_at' => '2026-10-18 02:30:00']);
        $this->patchJson('/api/appointment-reminders/'.$id, ['notes' => 'Thiết bị cũ', 'expected_data_revision' => $child->data_revision])
            ->assertConflict();
        $revision = ChildProfile::findOrFail($childId)->data_revision;

        Sanctum::actingAs($viewer);
        $this->getJson('/api/child-profiles/'.$childId.'/appointment-reminders')->assertOk()->assertJsonCount(1, 'data');
        $this->patchJson('/api/appointment-reminders/'.$id, ['notes' => 'Sửa ghi chú', 'expected_data_revision' => $revision])->assertForbidden();

        Sanctum::actingAs($owner);
        DB::table('appointment_reminders')->where('id', $id)->update(['notified_at' => now()]);
        $this->patchJson('/api/appointment-reminders/'.$id, ['remind_at' => '2026-10-18T10:30:00+07:00', 'expected_data_revision' => $revision])
            ->assertOk()->assertJsonPath('data.notification_revision', 2)->assertJsonPath('data.notified_at', null);
        $revision = ChildProfile::findOrFail($childId)->data_revision;
        $this->patchJson('/api/appointment-reminders/'.$id, ['is_completed' => true, 'expected_data_revision' => $revision])->assertOk()->assertJsonPath('data.is_completed', true);
        $revision = ChildProfile::findOrFail($childId)->data_revision;
        $this->patchJson('/api/appointment-reminders/'.$id, ['title' => 'Không được sửa', 'expected_data_revision' => $revision])->assertConflict();
        $this->patchJson('/api/appointment-reminders/'.$id, ['is_completed' => false, 'expected_data_revision' => $revision])->assertConflict();
        $this->patchJson('/api/appointment-reminders/'.$id, ['notes' => 'Đã hoàn thành', 'expected_data_revision' => $revision])->assertOk();
        $staleRevision = $revision;
        $revision = ChildProfile::findOrFail($childId)->data_revision;
        $this->deleteJson('/api/appointment-reminders/'.$id, ['confirmed' => true, 'expected_data_revision' => $staleRevision])->assertConflict();
        $this->deleteJson('/api/appointment-reminders/'.$id, ['confirmed' => true, 'expected_data_revision' => $revision])->assertOk();
        $this->assertDatabaseMissing('appointment_reminders', ['id' => $id]);
    }

    public function test_device_token_is_encrypted_rebound_on_account_switch_and_logout_is_installation_scoped(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $installationId = (string) Str::uuid();
        $firstToken = 'fcm-registration-token-for-account-one';
        $secondToken = 'fcm-registration-token-for-account-two';

        Sanctum::actingAs($first);
        $childId = $this->child();
        $this->putJson('/api/device-tokens', ['fcm_token' => $firstToken, 'device_type' => 'android', 'installation_id' => $installationId])->assertOk();
        $stored = DB::table('device_tokens')->where('installation_id', $installationId)->first();
        $this->assertNotSame($firstToken, $stored->fcm_token);
        $this->assertSame(hash('sha256', $firstToken), $stored->token_hash);
        $this->assertSame($firstToken, Crypt::decryptString($stored->fcm_token));
        $reminderId = DB::table('appointment_reminders')->insertGetId([
            'child_profile_id' => $childId, 'created_by' => $first->id, 'title' => 'Khám', 'type' => 'doctor_visit',
            'remind_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('notification_deliveries')->insert([
            'appointment_reminder_id' => $reminderId, 'notification_revision' => 1, 'user_id' => $first->id,
            'device_token_id' => $stored->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        Sanctum::actingAs($second);
        $this->putJson('/api/device-tokens', ['fcm_token' => $secondToken, 'device_type' => 'ios', 'installation_id' => $installationId])->assertOk();
        $rebound = DB::table('device_tokens')->where('installation_id', $installationId)->first();
        $this->assertSame($second->id, $rebound->user_id);
        $this->assertSame(hash('sha256', $secondToken), $rebound->token_hash);
        $this->assertNotSame($stored->id, $rebound->id);
        $this->assertDatabaseHas('notification_deliveries', ['appointment_reminder_id' => $reminderId, 'device_token_id' => null, 'cancelled_at' => now()]);
        $this->putJson('/api/device-tokens', ['fcm_token' => $secondToken, 'device_type' => 'ios', 'installation_id' => (string) Str::uuid()])->assertConflict();
        Sanctum::actingAs($first);
        $this->deleteJson('/api/device-tokens', ['installation_id' => $installationId])->assertOk();
        $this->assertDatabaseHas('device_tokens', ['installation_id' => $installationId, 'user_id' => $second->id]);

        Sanctum::actingAs($second);
        $this->postJson('/api/auth/logout', ['installation_id' => $installationId])->assertOk();
        $this->assertDatabaseMissing('device_tokens', ['installation_id' => $installationId]);
    }

    public function test_notification_preferences_default_on_and_allow_individual_updates(): void
    {
        $user = $this->owner();
        $this->getJson('/api/notification-preferences')->assertOk()
            ->assertJsonPath('data.vaccination_enabled', true)
            ->assertJsonPath('data.appointment_enabled', true);
        $this->putJson('/api/notification-preferences', ['appointment_enabled' => false])->assertOk()
            ->assertJsonPath('data.appointment_enabled', false)
            ->assertJsonPath('data.memory_enabled', true);
        $this->assertDatabaseHas('notification_preferences', ['user_id' => $user->id, 'appointment_enabled' => false, 'memory_enabled' => true]);
    }

    public function test_due_appointment_reminder_uses_fcm_and_persists_delivery_only_after_acceptance(): void
    {
        $owner = $this->owner();
        $childId = $this->child();
        $child = ChildProfile::findOrFail($childId);
        $fcmToken = 'fcm-reminder-test-registration-token';
        $coOwner = User::factory()->create();
        $this->member($child->family_space_id, $coOwner, 'owner');
        foreach ([[$owner, $fcmToken], [$coOwner, 'fcm-co-owner-reminder-test-token']] as [$recipient, $token]) {
            DB::table('device_tokens')->insert([
                'user_id' => $recipient->id, 'fcm_token' => Crypt::encryptString($token),
                'token_hash' => hash('sha256', $token), 'device_type' => 'android', 'installation_id' => (string) Str::uuid(),
                'last_used_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $reminderId = DB::table('appointment_reminders')->insertGetId([
            'child_profile_id' => $childId, 'created_by' => $owner->id, 'title' => 'Thuốc theo lời bác sĩ',
            'type' => 'medicine', 'remind_at' => now('UTC')->subMinute(), 'is_completed' => false,
            'notification_revision' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $clientEmail = 'test-'.Str::uuid().'@babi.invalid';
        $projectId = 'babi-test-project';
        $credentialPath = tempnam(sys_get_temp_dir(), 'babi-fcm-');
        file_put_contents($credentialPath, json_encode([
            'client_email' => $clientEmail, 'private_key' => 'test-key-unused-with-cached-token',
            'project_id' => $projectId, 'token_uri' => 'https://oauth2.googleapis.com/token',
        ], JSON_THROW_ON_ERROR));
        $this->beforeApplicationDestroyed(fn () => @unlink($credentialPath));
        config(['services.fcm.credentials' => $credentialPath, 'services.fcm.project_id' => $projectId]);
        Cache::put('babi:fcm:oauth:'.hash('sha256', $clientEmail.'|'.$projectId), 'test-oauth-token', now()->addMinutes(30));
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'oauth2.googleapis.com/token')) {
                return Http::response(['access_token' => 'test-oauth-token', 'expires_in' => 3600], 200);
            }

            return Http::response(['name' => 'projects/babi-test-project/messages/accepted'], 200);
        });

        $this->artisan('babi:send-appointment-reminders')->assertSuccessful();
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'fcm.googleapis.com/v1/projects/babi-test-project/messages:send')
            && $request->hasHeader('Authorization', 'Bearer test-oauth-token')
            && $request['message']['token'] === $fcmToken
            && str_contains($request['message']['notification']['body'], 'không phải hướng dẫn liều dùng'));
        $this->assertNotNull(DB::table('appointment_reminders')->where('id', $reminderId)->value('notified_at'));
        $this->assertDatabaseHas('notification_deliveries', ['appointment_reminder_id' => $reminderId, 'user_id' => $owner->id, 'sent_at' => now()]);
        $this->assertDatabaseHas('notification_deliveries', ['appointment_reminder_id' => $reminderId, 'user_id' => $coOwner->id, 'sent_at' => now()]);
    }

    public function test_appointment_delivery_with_unknown_provider_result_is_not_retried(): void
    {
        $owner = $this->owner();
        $childId = $this->child();
        $child = ChildProfile::findOrFail($childId);
        $token = 'fcm-unknown-result-test-token';
        DB::table('device_tokens')->insert([
            'user_id' => $owner->id, 'fcm_token' => Crypt::encryptString($token),
            'token_hash' => hash('sha256', $token), 'device_type' => 'ios', 'installation_id' => (string) Str::uuid(),
            'last_used_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $reminderId = DB::table('appointment_reminders')->insertGetId([
            'child_profile_id' => $childId, 'created_by' => $owner->id, 'title' => 'Khám', 'type' => 'doctor_visit',
            'remind_at' => now('UTC')->subMinute(), 'is_completed' => false, 'notification_revision' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $fcm = \Mockery::mock(FcmClient::class);
        $fcm->shouldReceive('isConfigured')->twice()->andReturnTrue();
        $fcm->shouldReceive('send')->once()->andThrow(new FcmDeliveryUnknownException('ambiguous result'));
        $this->app->instance(FcmClient::class, $fcm);

        $this->artisan('babi:send-appointment-reminders')->assertExitCode(1);
        $delivery = DB::table('notification_deliveries')->where('appointment_reminder_id', $reminderId)->first();
        $this->assertNotNull($delivery->unknown_at);
        $this->assertSame('provider_result_unknown', $delivery->reason_code);
        $this->assertSame(1, (int) $delivery->attempt_count);
        $this->assertNull($delivery->claim_token);

        $this->artisan('babi:send-appointment-reminders')->assertSuccessful();
        $this->assertSame(1, (int) DB::table('notification_deliveries')->where('id', $delivery->id)->value('attempt_count'));
    }

    public function test_appointment_delivery_retries_are_backed_off_and_bounded(): void
    {
        $owner = $this->owner();
        $childId = $this->child();
        $token = 'fcm-retry-limit-test-token';
        DB::table('device_tokens')->insert([
            'user_id' => $owner->id, 'fcm_token' => Crypt::encryptString($token),
            'token_hash' => hash('sha256', $token), 'device_type' => 'android', 'installation_id' => (string) Str::uuid(),
            'last_used_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $reminderId = DB::table('appointment_reminders')->insertGetId([
            'child_profile_id' => $childId, 'created_by' => $owner->id, 'title' => 'Khám', 'type' => 'doctor_visit',
            'remind_at' => now('UTC')->subMinute(), 'is_completed' => false, 'notification_revision' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $fcm = \Mockery::mock(FcmClient::class);
        $fcm->shouldReceive('isConfigured')->times(4)->andReturnTrue();
        $fcm->shouldReceive('send')->times(3)->andThrow(new FcmRetryableException('temporary failure'));
        $this->app->instance(FcmClient::class, $fcm);

        $this->artisan('babi:send-appointment-reminders')->assertExitCode(1);
        $delivery = DB::table('notification_deliveries')->where('appointment_reminder_id', $reminderId)->first();
        $this->assertSame(1, (int) $delivery->attempt_count);
        $this->assertSame(now()->addMinutes(15)->toDateTimeString(), $delivery->retry_after);
        $this->travel(16)->minutes();
        $this->artisan('babi:send-appointment-reminders')->assertExitCode(1);
        $delivery = DB::table('notification_deliveries')->where('id', $delivery->id)->first();
        $this->assertSame(2, (int) $delivery->attempt_count);
        $this->assertSame(now()->addMinutes(30)->toDateTimeString(), $delivery->retry_after);
        $this->travel(31)->minutes();
        $this->artisan('babi:send-appointment-reminders')->assertExitCode(1);
        $delivery = DB::table('notification_deliveries')->where('id', $delivery->id)->first();
        $this->assertSame(3, (int) $delivery->attempt_count);
        $this->assertNotNull($delivery->failed_at);
        $this->assertSame('retry_limit_reached', $delivery->reason_code);
        $this->assertNull($delivery->retry_after);

        $this->travel(2)->hours();
        $this->artisan('babi:send-appointment-reminders')->assertSuccessful();
        $this->assertSame(3, (int) DB::table('notification_deliveries')->where('id', $delivery->id)->value('attempt_count'));
    }

    public function test_exhausted_stale_appointment_push_claim_is_finalized_without_a_fourth_attempt(): void
    {
        $owner = $this->owner();
        $childId = $this->child();
        $reminderId = DB::table('appointment_reminders')->insertGetId([
            'child_profile_id' => $childId, 'created_by' => $owner->id, 'title' => 'Khám', 'type' => 'doctor_visit',
            'remind_at' => now('UTC')->subMinute(), 'is_completed' => false, 'notification_revision' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $deliveryId = DB::table('notification_deliveries')->insertGetId([
            'appointment_reminder_id' => $reminderId, 'notification_revision' => 1, 'user_id' => $owner->id,
            'device_token_id' => null, 'claim_token' => (string) Str::uuid(), 'claimed_at' => now()->subMinutes(6),
            'attempt_count' => 3, 'retry_after' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $fcm = \Mockery::mock(FcmClient::class);
        $fcm->shouldReceive('isConfigured')->once()->andReturnTrue();
        $fcm->shouldReceive('send')->never();
        $this->app->instance(FcmClient::class, $fcm);

        $this->artisan('babi:send-appointment-reminders')->assertSuccessful();

        $this->assertDatabaseHas('notification_deliveries', [
            'id' => $deliveryId, 'attempt_count' => 3, 'failed_at' => now(), 'reason_code' => 'retry_limit_reached',
            'claim_token' => null, 'claimed_at' => null, 'retry_after' => null,
        ]);
    }

    public function test_fcm_unregistered_token_is_removed_without_blocking_other_device_delivery(): void
    {
        $owner = $this->owner();
        $childId = $this->child();
        $reminderId = DB::table('appointment_reminders')->insertGetId([
            'child_profile_id' => $childId, 'created_by' => $owner->id, 'title' => 'Khám', 'type' => 'doctor_visit',
            'remind_at' => now('UTC')->subMinute(), 'is_completed' => false, 'notification_revision' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $badToken = 'fcm-unregistered-token-for-test';
        $goodToken = 'fcm-valid-registration-token-for-test';
        $badId = DB::table('device_tokens')->insertGetId([
            'user_id' => $owner->id, 'fcm_token' => Crypt::encryptString($badToken), 'token_hash' => hash('sha256', $badToken),
            'device_type' => 'android', 'installation_id' => (string) Str::uuid(), 'last_used_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $goodId = DB::table('device_tokens')->insertGetId([
            'user_id' => $owner->id, 'fcm_token' => Crypt::encryptString($goodToken), 'token_hash' => hash('sha256', $goodToken),
            'device_type' => 'ios', 'installation_id' => (string) Str::uuid(), 'last_used_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $clientEmail = 'test-'.Str::uuid().'@babi.invalid';
        $projectId = 'babi-test-project';
        $credentialPath = tempnam(sys_get_temp_dir(), 'babi-fcm-');
        file_put_contents($credentialPath, json_encode([
            'client_email' => $clientEmail, 'private_key' => 'test-key-unused-with-cached-token', 'project_id' => $projectId,
        ], JSON_THROW_ON_ERROR));
        $this->beforeApplicationDestroyed(fn () => @unlink($credentialPath));
        config(['services.fcm.credentials' => $credentialPath, 'services.fcm.project_id' => $projectId]);
        Cache::put('babi:fcm:oauth:'.hash('sha256', $clientEmail.'|'.$projectId), 'test-oauth-token', now()->addMinutes(30));
        Http::fake(fn ($request) => $request['message']['token'] === $badToken
            ? Http::response(['error' => ['status' => 'NOT_FOUND', 'details' => [['errorCode' => 'UNREGISTERED']]]], 404)
            : Http::response(['name' => 'projects/babi-test-project/messages/accepted'], 200));

        $this->artisan('babi:send-appointment-reminders')->assertSuccessful();
        $this->assertDatabaseMissing('device_tokens', ['id' => $badId]);
        $this->assertDatabaseHas('device_tokens', ['id' => $goodId, 'user_id' => $owner->id]);
        $this->assertDatabaseHas('notification_deliveries', ['appointment_reminder_id' => $reminderId, 'failed_at' => now(), 'sent_at' => null]);
        $this->assertNotNull(DB::table('appointment_reminders')->where('id', $reminderId)->value('notified_at'));
    }

    public function test_appointment_notification_is_skipped_without_current_consent_or_when_preference_disabled(): void
    {
        $owner = $this->owner();
        $childId = $this->child();
        $fcmToken = 'fcm-disabled-preference-test-token';
        DB::table('device_tokens')->insert([
            'user_id' => $owner->id, 'fcm_token' => Crypt::encryptString($fcmToken),
            'token_hash' => hash('sha256', $fcmToken), 'device_type' => 'ios', 'installation_id' => (string) Str::uuid(),
            'last_used_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('notification_preferences')->insert([
            'user_id' => $owner->id, 'appointment_enabled' => false, 'vaccination_enabled' => true,
            'memory_enabled' => true, 'milestone_enabled' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $reminderId = DB::table('appointment_reminders')->insertGetId([
            'child_profile_id' => $childId, 'created_by' => $owner->id, 'title' => 'Khám', 'type' => 'doctor_visit',
            'remind_at' => now('UTC')->subMinute(), 'is_completed' => false, 'notification_revision' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Http::fake();

        $this->artisan('babi:send-appointment-reminders')->assertSuccessful();
        Http::assertNothingSent();
        $this->assertNull(DB::table('appointment_reminders')->where('id', $reminderId)->value('notified_at'));
        $this->assertDatabaseCount('notification_deliveries', 0);

        DB::table('notification_preferences')->where('user_id', $owner->id)->update(['appointment_enabled' => true]);
        DB::table('child_data_consents')->where('child_profile_id', $childId)->where('purpose', 'storage')->update(['withdrawn_at' => now()]);
        $this->artisan('babi:send-appointment-reminders')->assertSuccessful();
        Http::assertNothingSent();
        $this->assertNull(DB::table('appointment_reminders')->where('id', $reminderId)->value('notified_at'));
    }

    public function test_due_appointment_reminder_stays_pending_when_firebase_is_not_configured(): void
    {
        $owner = $this->owner();
        $childId = $this->child();
        $fcmToken = 'fcm-no-credentials-test-token';
        DB::table('device_tokens')->insert([
            'user_id' => $owner->id, 'fcm_token' => Crypt::encryptString($fcmToken),
            'token_hash' => hash('sha256', $fcmToken), 'device_type' => 'android', 'installation_id' => (string) Str::uuid(),
            'last_used_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $reminderId = DB::table('appointment_reminders')->insertGetId([
            'child_profile_id' => $childId, 'created_by' => $owner->id, 'title' => 'Khám', 'type' => 'doctor_visit',
            'remind_at' => now('UTC')->subMinute(), 'is_completed' => false, 'notification_revision' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        config(['services.fcm.credentials' => null, 'services.fcm.project_id' => null]);
        Http::fake();

        $this->artisan('babi:send-appointment-reminders')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertDatabaseCount('notification_deliveries', 0);
        $this->assertNull(DB::table('appointment_reminders')->where('id', $reminderId)->value('notified_at'));
    }

    public function test_reminder_delivery_claim_blocks_overlap_and_reclaims_stale_attempt(): void
    {
        $owner = $this->owner();
        $childId = $this->child();
        $schedule = DB::table('vaccination_schedules')->where('child_profile_id', $childId)->orderBy('id')->first();
        DB::table('vaccination_schedules')->where('id', $schedule->id)->update(['due_date' => '2026-10-24']);
        $identity = [
            'vaccination_schedule_id' => $schedule->id,
            'reminder_date' => '2026-10-24',
            'days_before' => 7,
            'email' => $owner->email,
        ];
        $claimToken = (string) Str::uuid();
        DB::table('vaccination_reminder_deliveries')->insert($identity + [
            'claim_token' => $claimToken,
            'claimed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Artisan::call('babi:send-vaccination-reminders', ['--date' => '2026-10-17', '--time' => '08:00']);
        $this->assertDatabaseHas('vaccination_reminder_deliveries', $identity + [
            'claim_token' => $claimToken,
            'sent_at' => null,
        ]);

        DB::table('vaccination_reminder_deliveries')->where($identity)->update(['claimed_at' => now()->subMinutes(16)]);
        Artisan::call('babi:send-vaccination-reminders', ['--date' => '2026-10-17', '--time' => '08:00']);
        $this->assertDatabaseMissing('vaccination_reminder_deliveries', $identity + [
            'claim_token' => $claimToken,
        ]);
        $this->assertNotNull(DB::table('vaccination_reminder_deliveries')->where($identity)->value('sent_at'));
        $this->assertNull(DB::table('vaccination_reminder_deliveries')->where($identity)->value('claimed_at'));
    }

    public function test_failed_reminder_send_waits_before_retrying(): void
    {
        $this->owner();
        $childId = $this->child();
        $schedule = DB::table('vaccination_schedules')->where('child_profile_id', $childId)->orderBy('id')->first();
        DB::table('vaccination_schedules')->where('id', $schedule->id)->update(['due_date' => '2026-10-24']);

        $attempt = 0;
        Mail::shouldReceive('raw')->twice()->andReturnUsing(function () use (&$attempt) {
            if (++$attempt === 1) {
                throw new \RuntimeException('SMTP unavailable');
            }

            return null;
        });
        $this->assertSame(1, Artisan::call('babi:send-vaccination-reminders', ['--date' => '2026-10-17', '--time' => '08:00']));
        $delivery = DB::table('vaccination_reminder_deliveries')->where('vaccination_schedule_id', $schedule->id)->first();
        $this->assertNotNull($delivery);
        $this->assertNull($delivery->sent_at);
        $this->assertNull($delivery->claim_token);
        $this->assertNull($delivery->claimed_at);
        $this->assertSame(now()->addMinutes(15)->toDateTimeString(), $delivery->retry_after);

        $this->assertSame(0, Artisan::call('babi:send-vaccination-reminders', ['--date' => '2026-10-17', '--time' => '08:00']));
        $this->assertNull(DB::table('vaccination_reminder_deliveries')->where('id', $delivery->id)->value('sent_at'));
        $this->travel(16)->minutes();
        $this->assertSame(0, Artisan::call('babi:send-vaccination-reminders', ['--date' => '2026-10-17', '--time' => '08:00']));
        $this->assertNotNull(DB::table('vaccination_reminder_deliveries')->where('id', $delivery->id)->value('sent_at'));
    }

    public function test_vaccination_reminder_email_retries_are_bounded(): void
    {
        $this->owner();
        $childId = $this->child();
        $schedule = DB::table('vaccination_schedules')->where('child_profile_id', $childId)->orderBy('id')->first();
        DB::table('vaccination_schedules')->where('id', $schedule->id)->update(['due_date' => '2026-10-24']);

        Mail::shouldReceive('raw')->times(3)->andThrow(new \RuntimeException('SMTP unavailable'));
        $options = ['--date' => '2026-10-17', '--time' => '08:00'];
        $this->assertSame(1, Artisan::call('babi:send-vaccination-reminders', $options));
        $delivery = DB::table('vaccination_reminder_deliveries')->where('vaccination_schedule_id', $schedule->id)->first();
        $this->assertSame(1, (int) $delivery->attempt_count);
        $this->assertSame(now()->addMinutes(15)->toDateTimeString(), $delivery->retry_after);
        $this->assertNull($delivery->failed_at);

        $this->travel(16)->minutes();
        $this->assertSame(1, Artisan::call('babi:send-vaccination-reminders', $options));
        $delivery = DB::table('vaccination_reminder_deliveries')->where('id', $delivery->id)->first();
        $this->assertSame(2, (int) $delivery->attempt_count);
        $this->assertSame(now()->addMinutes(30)->toDateTimeString(), $delivery->retry_after);
        $this->assertNull($delivery->failed_at);

        $this->travel(31)->minutes();
        $this->assertSame(1, Artisan::call('babi:send-vaccination-reminders', $options));
        $delivery = DB::table('vaccination_reminder_deliveries')->where('id', $delivery->id)->first();
        $this->assertSame(3, (int) $delivery->attempt_count);
        $this->assertNotNull($delivery->failed_at);
        $this->assertNull($delivery->retry_after);
        $this->assertSame('retry_limit_reached', $delivery->reason_code);
        $this->assertNull($delivery->claim_token);
        $this->assertSame(0, Artisan::call('babi:send-vaccination-reminders', $options));
    }

    public function test_vaccination_email_exhausted_stale_claim_is_finalized_without_resending(): void
    {
        $owner = $this->owner();
        $childId = $this->child();
        $scheduleId = DB::table('vaccination_schedules')->where('child_profile_id', $childId)->value('id');
        DB::table('vaccination_schedules')->where('id', $scheduleId)->update(['due_date' => '2026-10-24']);
        $id = DB::table('vaccination_reminder_deliveries')->insertGetId([
            'vaccination_schedule_id' => $scheduleId,
            'reminder_date' => '2026-10-24',
            'days_before' => 7,
            'email' => $owner->email,
            'claim_token' => (string) Str::uuid(),
            'claimed_at' => now()->subMinutes(16),
            'attempt_count' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Mail::shouldReceive('raw')->never();
        Artisan::call('babi:send-vaccination-reminders', ['--date' => '2026-10-17', '--time' => '08:00']);

        $delivery = DB::table('vaccination_reminder_deliveries')->find($id);
        $this->assertNotNull($delivery->failed_at);
        $this->assertNull($delivery->claim_token);
        $this->assertNull($delivery->claimed_at);
        $this->assertSame('retry_limit_reached', $delivery->reason_code);
    }

    public function test_child_avatar_is_optional_private_and_idempotent(): void
    {
        Storage::fake('local');
        $owner = $this->owner();
        $key = (string) Str::uuid();
        $payload = $this->payload() + ['avatar' => UploadedFile::fake()->image('child.png', 80, 80)];
        $result = $this->post('/api/child-profiles', $payload, ['Accept' => 'application/json', 'X-Operation-Key' => $key])->assertCreated();
        $id = $result->json('data.id');
        $this->assertSame('/api/child-profiles/'.$id.'/avatar', $result->json('data.avatar_url'));
        $this->assertArrayNotHasKey('avatar_path', $result->json('data'));
        $this->post('/api/child-profiles', $payload, ['Accept' => 'application/json', 'X-Operation-Key' => $key])->assertCreated()->assertJsonPath('data.id', $id);
        $this->assertCount(1, Storage::disk('local')->allFiles('child-avatars'));
        $this->get('/api/child-profiles/'.$id.'/avatar')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $viewer = User::factory()->create();
        Sanctum::actingAs($viewer);
        $this->getJson('/api/child-profiles/'.$id.'/avatar')->assertForbidden();
        $this->member(ChildProfile::findOrFail($id)->family_space_id, $viewer);
        $this->get('/api/child-profiles/'.$id.'/avatar')->assertOk();
        Sanctum::actingAs($owner);
        $this->post('/api/child-profiles', $this->payload() + ['avatar' => UploadedFile::fake()->image('huge.png')->size(10241)], ['Accept' => 'application/json', 'X-Operation-Key' => (string) Str::uuid()])->assertUnprocessable();
        $this->assertDatabaseCount('child_profiles', 1);
    }

    public function test_child_creation_survives_milestone_dispatch_failure_after_commit(): void
    {
        $this->owner();
        Bus::shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('Queue unavailable.'));
        $key = (string) Str::uuid();

        $this->postJson('/api/child-profiles', $this->payload(), ['X-Operation-Key' => $key])
            ->assertCreated()->assertJsonPath('meta.vaccine_catalog_ready', true);

        $this->assertDatabaseCount('child_profiles', 1);
        $this->assertDatabaseCount('child_data_consents', 1);
    }

    public function test_consent_grant_survives_milestone_dispatch_failure(): void
    {
        $this->owner();
        $childId = $this->child();
        $consentId = DB::table('child_data_consents')->where('child_profile_id', $childId)->value('id');
        Bus::shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('Queue unavailable.'));

        $this->postJson('/api/child-profiles/'.$childId.'/consents', [
            'expected_consent_revision' => 1,
            'policy_version' => config('babi.policy_version'),
            'representative_confirmed' => true,
            'family_sharing_acknowledged' => true,
        ], ['X-Operation-Key' => (string) Str::uuid()])->assertOk();

        $this->assertNotNull(DB::table('child_data_consents')->where('id', $consentId)->value('withdrawn_at'));
        $this->assertDatabaseCount('child_data_consents', 2);
    }

    public function test_owner_can_export_full_profile_after_withdrawing_storage_consent(): void
    {
        $owner = $this->owner();
        $childId = $this->child();
        Storage::fake('local');
        Storage::disk('local')->put('media/private-photo.jpg', 'private-image-bytes');
        $memoryId = DB::table('memories')->insertGetId([
            'child_profile_id' => $childId, 'created_by' => $owner->id, 'caption' => 'Ngày đầu đi chơi',
            'milestone_id' => null, 'memory_date' => '2026-09-01', 'status' => 'published', 'source_manifest' => '[]',
            'processing_generation' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('memory_comments')->insert([
            'memory_id' => $memoryId, 'user_id' => $owner->id, 'content' => 'Kỷ niệm gia đình',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('media_files')->insert([
            'memory_id' => $memoryId, 'file_path' => 'media/private-photo.jpg', 'file_type' => 'image',
            'mime_type' => 'image/jpeg', 'file_size_kb' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $consentId = DB::table('child_data_consents')->where('child_profile_id', $childId)->whereNull('withdrawn_at')->value('id');
        $this->postJson('/api/child-data-consents/'.$consentId.'/withdraw', [
            'expected_consent_revision' => 1, 'confirmed' => true,
        ], ['X-Operation-Key' => (string) Str::uuid()])->assertOk();

        $exportKey = (string) Str::uuid();
        $exportRequest = ['current_password' => 'password'];
        $exportHeaders = ['X-Operation-Key' => $exportKey];
        $this->postJson('/api/child-profiles/'.$childId.'/exports', ['current_password' => 'wrong-password'], $exportHeaders)->assertUnprocessable();
        $this->assertDatabaseCount('child_profile_exports', 0);
        $firstResponse = $this->postJson('/api/child-profiles/'.$childId.'/exports', $exportRequest, $exportHeaders)
            ->assertStatus(202)->assertJsonPath('replayed', false);
        $export = $firstResponse->json('data');
        $replay = $this->postJson('/api/child-profiles/'.$childId.'/exports', $exportRequest, $exportHeaders)
            ->assertStatus(202)->assertJsonPath('replayed', true)->json('data');
        $this->assertSame($export['id'], $replay['id']);
        $this->assertDatabaseCount('child_profile_exports', 1);
        $this->assertSame('ready', $export['status']);
        $this->assertSame(2, $export['snapshot_data_revision']);
        $this->assertNotNull($export['expires_at']);
        $this->assertEquals(now()->addHours(24)->timestamp, strtotime($export['expires_at']));
        $viewer = User::factory()->create();
        $this->member(ChildProfile::findOrFail($childId)->family_space_id, $viewer);
        Sanctum::actingAs($viewer);
        $this->postJson('/api/child-profiles/'.$childId.'/exports', $exportRequest, ['X-Operation-Key' => (string) Str::uuid()])->assertForbidden();
        Sanctum::actingAs($owner);
        $download = $this->get('/api/child-profile-exports/'.$export['id'].'/download')->assertOk()
            ->assertHeader('Content-Type', 'application/zip')->assertHeader('Cache-Control', 'no-store, private');
        $archivePath = Storage::disk('local')->path(DB::table('child_profile_exports')->where('id', $export['id'])->value('archive_path'));
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($archivePath) === true);
        $payload = json_decode($zip->getFromName('data.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('Bé An', $payload['child']['name']);
        $this->assertSame('Ngày đầu đi chơi', $payload['memories'][0]['caption']);
        $this->assertSame('Kỷ niệm gia đình', $payload['comments'][0]['content']);
        $this->assertSame('media/memory-'.$memoryId.'-file-1.jpg', $payload['media_files'][0]['archive_path']);
        $this->assertSame('private-image-bytes', $zip->getFromName($payload['media_files'][0]['archive_path']));
        $zip->close();
        $this->travel(25)->hours();
        $this->get('/api/child-profile-exports/'.$export['id'].'/download')->assertStatus(410);
        $this->assertDatabaseHas('child_profile_exports', ['id' => $export['id'], 'status' => 'expired', 'archive_path' => null]);
        Artisan::call('babi:reconcile-media');
        Storage::disk('local')->assertMissing('exports/'.basename($archivePath));
    }

    public function test_queued_export_is_not_expired_before_archive_is_ready(): void
    {
        $owner = $this->owner();
        $childId = $this->child();
        $exportId = DB::table('child_profile_exports')->insertGetId([
            'child_profile_id' => $childId,
            'requested_by' => $owner->id,
            'operation_key' => (string) Str::uuid(),
            'snapshot_data_revision' => 1,
            'status' => 'queued',
            'expires_at' => now()->subDay(), // legacy request-time expiry must not expire an unfinished export
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subMinutes(4),
        ]);

        $this->getJson('/api/child-profile-exports/'.$exportId)->assertOk()->assertJsonPath('data.status', 'queued');
        Artisan::call('babi:reconcile-media');
        $this->assertDatabaseHas('child_profile_exports', ['id' => $exportId, 'status' => 'queued']);
        $this->assertDatabaseCount('storage_cleanups', 0);
    }

    public function test_export_request_remains_successful_when_queue_dispatch_fails(): void
    {
        $this->owner();
        $childId = $this->child();
        $operationKey = (string) Str::uuid();
        Bus::shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('Queue unavailable.'));

        $response = $this->postJson('/api/child-profiles/'.$childId.'/exports', ['current_password' => 'password'], [
            'X-Operation-Key' => $operationKey,
        ])->assertStatus(202)->assertJsonPath('data.status', 'queued')->assertJsonPath('replayed', false);

        $exportId = $response->json('data.id');
        $this->assertDatabaseHas('child_profile_exports', [
            'id' => $exportId, 'status' => 'queued', 'operation_key' => strtolower($operationKey),
        ]);
        $this->postJson('/api/child-profiles/'.$childId.'/exports', ['current_password' => 'password'], [
            'X-Operation-Key' => $operationKey,
        ])->assertStatus(202)->assertJsonPath('data.id', $exportId)->assertJsonPath('replayed', true);
    }

    public function test_reconcile_retries_stale_queued_export_requests(): void
    {
        $owner = $this->owner();
        $childId = $this->child();
        Storage::fake('local');
        $exportIds = [];
        foreach (['queued', 'processing'] as $status) {
            $exportIds[] = DB::table('child_profile_exports')->insertGetId([
                'child_profile_id' => $childId,
                'requested_by' => $owner->id,
                'operation_key' => (string) Str::uuid(),
                'snapshot_data_revision' => 1,
                'status' => $status,
                'expires_at' => null,
                'created_at' => now()->subMinutes(6),
                'updated_at' => now()->subMinutes(6),
            ]);
        }

        Artisan::call('babi:reconcile-media');

        foreach ($exportIds as $exportId) {
            $this->assertDatabaseHas('child_profile_exports', ['id' => $exportId, 'status' => 'ready']);
            $archivePath = DB::table('child_profile_exports')->where('id', $exportId)->value('archive_path');
            $this->assertNotNull($archivePath);
            Storage::disk('local')->assertExists($archivePath);
        }
    }

    public function test_owner_deletion_cascades_profile_data_and_durably_cleans_private_files(): void
    {
        $owner = $this->owner();
        $childId = $this->child();
        Storage::fake('local');
        $paths = ['child-avatars/child.png', 'media/source.jpg', 'media/published.jpg', 'exports/profile.zip'];
        foreach ($paths as $path) {
            Storage::disk('local')->put($path, 'private-bytes');
        }
        DB::table('child_profiles')->where('id', $childId)->update(['avatar_path' => $paths[0], 'avatar_mime' => 'image/png']);
        $memoryId = DB::table('memories')->insertGetId([
            'child_profile_id' => $childId, 'created_by' => $owner->id, 'caption' => 'Kỷ niệm', 'milestone_id' => null,
            'memory_date' => '2026-09-01', 'status' => 'failed', 'source_manifest' => json_encode([['path' => $paths[1], 'mime' => 'image/jpeg']]),
            'processing_generation' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('media_files')->insert(['memory_id' => $memoryId, 'file_path' => $paths[2], 'file_type' => 'image', 'mime_type' => 'image/jpeg', 'file_size_kb' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('child_profile_exports')->insert([
            'child_profile_id' => $childId, 'requested_by' => $owner->id, 'snapshot_data_revision' => 1,
            'status' => 'ready', 'archive_path' => $paths[3], 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $consentId = DB::table('child_data_consents')->where('child_profile_id', $childId)->whereNull('withdrawn_at')->value('id');
        $this->postJson('/api/child-data-consents/'.$consentId.'/withdraw', [
            'expected_consent_revision' => 1, 'confirmed' => true,
        ], ['X-Operation-Key' => (string) Str::uuid()])->assertOk();

        $this->deleteJson('/api/child-profiles/'.$childId, ['expected_data_revision' => 2, 'confirmed' => true])
            ->assertOk()->assertJsonPath('data.deleted', true);
        $this->assertDatabaseMissing('child_profiles', ['id' => $childId]);
        $this->assertDatabaseCount('child_data_consents', 0);
        $this->assertDatabaseCount('vaccination_schedules', 0);
        $this->assertCount(1, DB::table('storage_cleanups')->get());
        Artisan::call('babi:reconcile-media');
        foreach ($paths as $path) {
            Storage::disk('local')->assertMissing($path);
        }
        $this->assertDatabaseCount('storage_cleanups', 0);
    }

    public function test_registration_normalizes_email_and_never_grants_admin_or_membership(): void
    {
        $r = $this->postJson('/api/auth/register', ['name' => 'An', 'email' => '  Parent@Example.com ', 'password' => 'password123', 'password_confirmation' => 'password123', 'is_admin' => true]);
        $r->assertCreated()->assertJsonPath('data.user.email', 'parent@example.com');
        $this->assertArrayNotHasKey('password', $r->json('data.user'));
        $user = User::first();
        $this->assertFalse($user->is_admin);
        $this->assertTrue(Hash::check('password123', $user->password));
        $this->assertDatabaseCount('family_members', 0);
        $this->postJson('/api/auth/register', ['name' => 'An', 'email' => 'PARENT@example.com', 'password' => 'password123', 'password_confirmation' => 'password123'])->assertUnprocessable();
    }

    public function test_registration_is_rate_limited_per_ip(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/register', [
                'name' => 'Rate limit test',
                'email' => "register-limit-{$i}@example.test",
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ])->assertCreated();
        }

        $this->postJson('/api/auth/register', [
            'name' => 'Rate limit test',
            'email' => 'register-limit-final@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertStatus(429);
        $this->assertDatabaseCount('users', 5);
    }

    public function test_relative_shares_link_without_email_and_only_owner_approves(): void
    {
        $owner = $this->owner();
        $child = $this->child();
        $space = ChildProfile::findOrFail($child)->family_space_id;
        $relative = User::factory()->create();
        $memberId = $this->member($space, $relative);
        Sanctum::actingAs($relative);
        $link = $this->postJson('/api/family-spaces/'.$space.'/join-links')->assertCreated()->json('data');
        $this->postJson('/api/family-spaces/'.$space.'/invitation-proposals', ['email' => 'old@example.test'])->assertStatus(410);
        $this->postJson('/api/family-invitation-proposals/1/approve')->assertStatus(410);
        $this->postJson('/api/family-spaces/'.$space.'/invitations', ['email' => 'no@example.test', 'invitation_type' => 'family_viewer', 'confirmed' => true])->assertForbidden();
        $this->assertDatabaseCount('family_invitations', 0);
        $this->assertDatabaseCount('family_invitation_proposals', 0);
        $recipient = User::factory()->create();
        Sanctum::actingAs($recipient);
        $id = $this->postJson('/api/family-space-join-requests', ['token' => $link['token']])->assertStatus(202)->json('data.id');
        $this->getJson('/api/child-profiles/'.$child)->assertForbidden();
        Sanctum::actingAs($relative);
        $this->postJson('/api/family-join-requests/'.$id.'/approve')->assertForbidden();
        $this->deleteJson('/api/family-join-links/'.$link['id'])->assertForbidden();
        Sanctum::actingAs($owner);
        $this->postJson('/api/family-join-requests/'.$id.'/approve')->assertOk()->assertJsonPath('data.role', 'viewer');
        Sanctum::actingAs($recipient);
        $this->getJson('/api/child-profiles/'.$child)->assertOk();
        DB::table('family_members')->where('id', $memberId)->delete();
        $this->postJson('/api/family-space-join-requests/preview', ['token' => $link['token']])->assertForbidden();
        $this->assertDatabaseCount('family_invitations', 0);
    }

    public function test_pre_registered_relative_accepts_invitation_without_registering_again_or_creating_child(): void
    {
        $registered = $this->postJson('/api/auth/register', ['name' => 'Grandparent test', 'email' => 'grandparent@example.test', 'password' => 'password123', 'password_confirmation' => 'password123'])->assertCreated();
        $relative = User::findOrFail($registered->json('data.user.id'));
        $this->assertDatabaseCount('family_members', 0);
        $this->assertDatabaseCount('child_profiles', 0);
        $relative->markEmailAsVerified();
        $owner = $this->owner();
        $child = $this->child();
        $space = ChildProfile::findOrFail($child)->family_space_id;
        $token = $this->invitation($space, $owner->id, $relative->email);
        $this->postJson('/api/auth/login', ['email' => $relative->email, 'password' => 'password123'])->assertOk()
            ->assertJsonPath('data.user.id', $relative->id);
        Sanctum::actingAs($relative);
        $this->postJson('/api/family-invitations/accept', ['token' => $token])->assertOk()->assertJsonPath('data.role', 'viewer');
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('family_spaces', 1);
        $this->assertDatabaseCount('child_profiles', 1);
        $this->assertDatabaseHas('family_members', ['user_id' => $relative->id, 'family_space_id' => $space, 'role' => 'viewer']);
    }

    public function test_real_bearer_token_expires_and_logout_revokes_only_current_session(): void
    {
        $user = User::factory()->create();
        $a = $user->createToken('a', ['*'], now()->addDays(7));
        $b = $user->createToken('b', ['*'], now()->addDays(7));
        $this->withToken($a->plainTextToken)->getJson('/api/auth/me')->assertOk();
        $this->withToken($a->plainTextToken)->postJson('/api/auth/logout')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($a->plainTextToken)->getJson('/api/auth/me')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($b->plainTextToken)->getJson('/api/auth/me')->assertOk();
        $this->travel(8)->days();
        $this->app['auth']->forgetGuards();
        $this->withToken($b->plainTextToken)->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_creation_is_atomic_idempotent_and_uses_calendar_months(): void
    {
        $this->owner();
        $key = (string) Str::uuid();
        $a = $this->postJson('/api/child-profiles', $this->payload(), ['X-Operation-Key' => $key])->assertCreated();
        $this->postJson('/api/child-profiles', $this->payload(), ['X-Operation-Key' => $key])->assertCreated()->assertJsonPath('data.id', $a->json('data.id'))->assertJsonPath('replayed', true);
        $this->postJson('/api/child-profiles', array_replace($this->payload(), ['name' => 'Other']), ['X-Operation-Key' => $key])->assertConflict();
        $this->assertDatabaseCount('family_spaces', 1);
        $this->assertDatabaseCount('child_profiles', 1);
        $this->assertDatabaseHas('vaccination_schedules', ['child_profile_id' => $a->json('data.id'), 'due_date' => '2026-02-28', 'status' => 'unrecorded']);
        $this->assertDatabaseHas('vaccination_schedules', ['due_date' => null, 'status' => 'unrecorded']);
        $this->getJson('/api/child-profiles/'.$a->json('data.id').'/vaccinations')
            ->assertOk()->assertJsonMissingPath('data.data.0.source_url');
        $this->assertDatabaseHas('vaccine_templates', ['schedule_version' => 'TEST-ONLY', 'source_url' => 'https://example.invalid/test-only']);
        $this->child();
        $this->assertDatabaseCount('family_spaces', 1);
    }

    public function test_missing_catalog_creates_child_without_inventing_vaccine_schedules(): void
    {
        $this->owner();
        config(['babi.vaccine_catalog_version' => null]);
        $response = $this->postJson('/api/child-profiles', $this->payload(), ['X-Operation-Key' => (string) Str::uuid()])
            ->assertCreated()->assertJsonPath('meta.vaccine_schedule_count', 0)->assertJsonPath('meta.vaccine_catalog_ready', false);
        $childId = $response->json('data.id');
        $this->assertDatabaseCount('family_spaces', 1);
        $this->assertDatabaseCount('family_members', 1);
        $this->assertDatabaseHas('child_profiles', ['id' => $childId]);
        $this->assertDatabaseHas('child_data_consents', ['child_profile_id' => $childId, 'purpose' => 'storage']);
        $this->assertDatabaseCount('operation_requests', 1);
        $this->assertDatabaseCount('vaccination_schedules', 0);

        $withdrawnResponse = $this->postJson('/api/child-profiles', array_replace($this->payload(), ['name' => 'Bé đã rút consent']), ['X-Operation-Key' => (string) Str::uuid()])
            ->assertCreated()->assertJsonPath('meta.vaccine_schedule_count', 0);
        $withdrawnChildId = $withdrawnResponse->json('data.id');
        DB::table('child_data_consents')->where('child_profile_id', $withdrawnChildId)->where('purpose', 'storage')->update(['withdrawn_at' => now()]);
        $this->assertDatabaseCount('operation_requests', 2);

        config(['babi.vaccine_catalog_version' => 'TEST-ONLY']);
        $this->artisan('babi:populate-missing-vaccination-schedules')->assertSuccessful();
        $this->assertDatabaseCount('vaccination_schedules', 2);
        $this->assertDatabaseHas('child_profiles', ['id' => $childId, 'data_revision' => 2]);
        $this->assertDatabaseHas('child_profiles', ['id' => $withdrawnChildId, 'data_revision' => 1]);
        $this->assertDatabaseMissing('vaccination_schedules', ['child_profile_id' => $withdrawnChildId]);
        $this->artisan('babi:populate-missing-vaccination-schedules')->assertSuccessful();
        $this->assertDatabaseCount('vaccination_schedules', 2);
        $this->assertDatabaseHas('child_profiles', ['id' => $childId, 'data_revision' => 2]);
        $this->assertDatabaseHas('child_profiles', ['id' => $withdrawnChildId, 'data_revision' => 1]);
    }

    public function test_viewer_and_other_space_cannot_write_or_read_outside_scope(): void
    {
        $this->owner();
        $id = $this->child();
        $child = ChildProfile::findOrFail($id);
        $viewer = User::factory()->create();
        $this->member($child->family_space_id, $viewer);
        Sanctum::actingAs($viewer);
        $this->getJson('/api/child-profiles/'.$id)->assertOk();
        $this->putJson('/api/child-profiles/'.$id, ['name' => 'Changed', 'date_of_birth' => '2026-01-31', 'gender' => 'female', 'expected_data_revision' => 1])->assertForbidden();
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));
        $this->getJson('/api/child-profiles/'.$id)->assertForbidden();
        $this->getJson('/api/child-profiles')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_co_owner_can_edit_and_last_owner_cannot_be_removed(): void
    {
        $first = $this->owner();
        $id = $this->child();
        $child = ChildProfile::findOrFail($id);
        $firstMember = DB::table('family_members')->where('user_id', $first->id)->value('id');
        $this->deleteJson('/api/family-members/'.$firstMember, ['confirmed' => true])->assertConflict();
        $other = User::factory()->create();
        $this->member($child->family_space_id, $other, 'owner');
        Sanctum::actingAs($other);
        $this->putJson('/api/child-profiles/'.$id, ['name' => 'Changed by co-owner', 'date_of_birth' => '2026-01-31', 'gender' => 'female', 'expected_data_revision' => 1])->assertOk();
        $this->patchJson('/api/family-members/'.$firstMember, ['action' => 'demote', 'confirmed' => true])->assertOk();
        $this->assertDatabaseHas('family_members', ['id' => $firstMember, 'role' => 'viewer']);
    }

    public function test_withdrawal_blocks_all_normal_reads_and_revisions_prevent_stale_grants(): void
    {
        $this->owner();
        $id = $this->child();
        $consentId = DB::table('child_data_consents')->value('id');
        $grantedAt = DB::table('child_data_consents')->where('id', $consentId)->value('granted_at');
        $this->postJson('/api/child-data-consents/'.$consentId.'/withdraw', ['expected_consent_revision' => 1, 'confirmed' => true], ['X-Operation-Key' => (string) Str::uuid()])->assertOk()->assertJsonPath('data.consent_revision', 2);
        $this->assertSame($grantedAt, DB::table('child_data_consents')->where('id', $consentId)->value('granted_at'));
        $this->getJson('/api/child-profiles/'.$id)->assertForbidden();
        $this->getJson('/api/child-profiles/'.$id.'/health-records')->assertForbidden();
        $this->getJson('/api/child-profiles/'.$id.'/consents')->assertOk();
        $data = ['expected_consent_revision' => 1, 'policy_version' => config('babi.policy_version'), 'representative_confirmed' => true, 'family_sharing_acknowledged' => true];
        $this->postJson('/api/child-profiles/'.$id.'/consents', $data, ['X-Operation-Key' => (string) Str::uuid()])->assertConflict();
        $data['expected_consent_revision'] = 2;
        $this->postJson('/api/child-profiles/'.$id.'/consents', $data, ['X-Operation-Key' => (string) Str::uuid()])->assertOk();
        $this->getJson('/api/child-profiles/'.$id)->assertOk();
    }

    private function invitation(int $space, int $inviter, string $email, string $type = 'family_viewer'): string
    {
        $token = Str::random(64);
        DB::table('family_invitations')->insert(['family_space_id' => $space, 'invited_by' => $inviter, 'email' => $email, 'invitation_type' => $type, 'token_hash' => hash('sha256', $token), 'status' => 'pending', 'expires_at' => now()->addDays(7), 'created_at' => now(), 'updated_at' => now()]);

        return $token;
    }

    public function test_invitation_grants_no_membership_until_correct_verified_recipient_accepts(): void
    {
        $owner = $this->owner();
        $id = $this->child();
        $space = ChildProfile::findOrFail($id)->family_space_id;
        $recipient = User::factory()->unverified()->create();
        $this->postJson('/api/family-spaces/'.$space.'/invitations', ['email' => 'new@example.com', 'invitation_type' => 'family_viewer', 'confirmed' => true])->assertCreated();
        $this->assertDatabaseCount('family_members', 1);
        $token = $this->invitation($space, $owner->id, $recipient->email);
        Sanctum::actingAs($recipient);
        $this->postJson('/api/family-invitations/preview', ['token' => $token])->assertOk()
            ->assertJsonPath('data.invited_email', $recipient->email)
            ->assertJsonPath('data.invitation_type', 'family_viewer')
            ->assertJsonPath('data.role', 'viewer')
            ->assertJsonPath('data.email_matches', true)
            ->assertJsonPath('data.email_verified', false)
            ->assertJsonPath('data.can_respond', false);
        $this->postJson('/api/family-invitations/accept', ['token' => $token])->assertForbidden();
        $recipient->markEmailAsVerified();
        Sanctum::actingAs($recipient->fresh());
        $this->postJson('/api/family-invitations/preview', ['token' => $token])->assertOk()
            ->assertJsonPath('data.can_respond', true);
        $this->postJson('/api/family-invitations/accept', ['token' => $token, 'role' => 'owner'])->assertOk()->assertJsonPath('data.role', 'viewer');
        $this->postJson('/api/family-invitations/accept', ['token' => $token])->assertConflict();
    }

    public function test_invitation_rejects_wrong_email_expiry_and_inviter_lost_authority(): void
    {
        $owner = $this->owner();
        $id = $this->child();
        $space = ChildProfile::findOrFail($id)->family_space_id;
        $recipient = User::factory()->create();
        $token = $this->invitation($space, $owner->id, $recipient->email);
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/family-invitations/preview', ['token' => $token])->assertOk()
            ->assertJsonPath('data.email_matches', false)
            ->assertJsonPath('data.can_respond', false);
        $this->postJson('/api/family-invitations/accept', ['token' => $token])->assertForbidden();
        Sanctum::actingAs($recipient);
        $this->travel(8)->days();
        $this->postJson('/api/family-invitations/accept', ['token' => $token])->assertStatus(410);
        $this->travelBack();
        // Restore the fixture clock, not the machine's current calendar date.
        $this->travelTo(now()->setDate(2026, 10, 17)->startOfDay());
        DB::table('users')->where('id', $owner->id)->update(['status' => 'deactivated']);
        $this->postJson('/api/family-invitations/accept', ['token' => $token])->assertForbidden();
    }

    public function test_truncated_family_tokens_return_actionable_vietnamese_validation_errors(): void
    {
        $this->owner();
        $invitationMessage = 'Mã lời mời không hợp lệ hoặc đã bị cắt bớt. Hãy mở lại toàn bộ liên kết trong email.';
        $joinMessage = 'Mã liên kết gia đình không hợp lệ hoặc đã bị cắt bớt. Hãy mở lại toàn bộ liên kết đã được chia sẻ.';

        foreach (['/api/family-invitations/preview', '/api/family-invitations/accept'] as $endpoint) {
            $this->postJson($endpoint, ['token' => 'short'])
                ->assertUnprocessable()
                ->assertJsonPath('message', $invitationMessage)
                ->assertJsonPath('errors.token.0', $invitationMessage);
        }

        foreach (['/api/family-space-join-requests/preview', '/api/family-space-join-requests'] as $endpoint) {
            $this->postJson($endpoint, ['token' => 'short'])
                ->assertUnprocessable()
                ->assertJsonPath('message', $joinMessage)
                ->assertJsonPath('errors.token.0', $joinMessage);
        }
    }

    public function test_health_records_require_outlier_confirmation_and_never_overwrite_same_day(): void
    {
        $this->owner();
        $id = $this->child();
        $data = ['child_profile_id' => $id, 'measured_at' => '2026-10-01', 'height_cm' => 5, 'weight_kg' => 8.5, 'measurement_method' => 'lying', 'expected_data_revision' => 1];
        $this->postJson('/api/health-records', $data)->assertUnprocessable();
        $this->postJson('/api/health-records', $data + ['outlier_confirmed' => true])->assertCreated();
        $data['expected_data_revision'] = 2;
        $this->postJson('/api/health-records', $data + ['outlier_confirmed' => true])->assertConflict();
        $this->assertDatabaseCount('health_records', 1);
        $this->getJson('/api/child-profiles/'.$id.'/health-records')->assertOk()->assertJsonPath('who_reference', null);
        $this->putJson('/api/child-profiles/'.$id, ['name' => 'An', 'date_of_birth' => '2026-10-02', 'gender' => 'female', 'expected_data_revision' => 2])->assertConflict();
    }

    public function test_unknown_vaccination_date_and_void_keep_audit_and_reset_schedule(): void
    {
        $this->owner();
        $id = $this->child();
        $schedule = DB::table('vaccination_schedules')->where('child_profile_id', $id)->value('id');
        $data = ['vaccine_name' => 'Reported by parent', 'date_precision' => 'unknown', 'vaccination_schedule_id' => $schedule, 'source' => 'parent_report', 'expected_data_revision' => 1];
        $record = $this->postJson('/api/child-profiles/'.$id.'/vaccination-records', $data)->assertCreated()->assertJsonPath('data.administered_date', null)->json('data.id');
        $this->assertDatabaseHas('vaccination_schedules', ['id' => $schedule, 'status' => 'recorded']);
        $data['expected_data_revision'] = 2;
        $this->postJson('/api/child-profiles/'.$id.'/vaccination-records', $data)->assertConflict();
        $this->postJson('/api/vaccination-records/'.$record.'/void', ['reason' => 'Ghi nhầm', 'expected_version' => 0, 'expected_data_revision' => 2])->assertOk()->assertJsonPath('data.status', 'voided');
        $this->assertDatabaseHas('vaccination_schedules', ['id' => $schedule, 'status' => 'unrecorded']);
        $this->assertDatabaseCount('vaccination_record_changes', 2);
        $this->deleteJson('/api/vaccination-records/'.$record)->assertStatus(405);
    }

    public function test_owner_can_confirm_vaccine_not_administered_and_later_record_or_void_it(): void
    {
        $owner = $this->owner();
        $childId = $this->child();
        $scheduleId = DB::table('vaccination_schedules')->where('child_profile_id', $childId)->value('id');

        $this->patchJson('/api/vaccinations/'.$scheduleId.'/mark-pending', [
            'confirmed' => true,
            'expected_data_revision' => 1,
        ])->assertOk()->assertJsonPath('data.status', 'pending');

        $viewer = User::factory()->create();
        $spaceId = ChildProfile::findOrFail($childId)->family_space_id;
        $this->member($spaceId, $viewer);
        Sanctum::actingAs($viewer);
        $this->patchJson('/api/vaccinations/'.$scheduleId.'/mark-pending', [
            'confirmed' => true,
            'expected_data_revision' => 2,
        ])->assertForbidden();

        Sanctum::actingAs($owner);
        $recordId = $this->postJson('/api/child-profiles/'.$childId.'/vaccination-records', [
            'vaccine_name' => 'Test vaccine',
            'date_precision' => 'exact',
            'administered_date' => '2026-02-28',
            'vaccination_schedule_id' => $scheduleId,
            'source' => 'parent_report',
            'expected_data_revision' => 2,
        ])->assertCreated()->json('data.id');
        $this->assertDatabaseHas('vaccination_schedules', ['id' => $scheduleId, 'status' => 'recorded']);

        $this->postJson('/api/vaccination-records/'.$recordId.'/void', [
            'reason' => 'Ghi nhận nhầm',
            'expected_version' => 0,
            'expected_data_revision' => 3,
        ])->assertOk()->assertJsonPath('data.status', 'voided');
        $this->assertDatabaseHas('vaccination_schedules', ['id' => $scheduleId, 'status' => 'unrecorded']);
        $this->assertDatabaseHas('vaccination_record_changes', [
            'vaccination_record_id' => $recordId,
            'changed_by' => $owner->id,
            'action' => 'void',
        ]);
    }

    public function test_registered_relative_verifies_email_from_family_link_then_waits_for_owner_approval(): void
    {
        $owner = $this->owner();
        $childId = $this->child();
        $spaceId = ChildProfile::findOrFail($childId)->family_space_id;
        $link = $this->postJson('/api/family-spaces/'.$spaceId.'/join-links')->assertCreated()->json('data');
        $joinToken = $link['token'];
        $email = 'relative-verification-flow@example.test';
        Notification::fake();

        $registration = $this->postJson('/api/auth/register', [
            'name' => 'Relative',
            'email' => $email,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertCreated();
        $relative = User::where('email', $email)->firstOrFail();
        $this->assertNotEmpty($registration->json('data.token'));
        Sanctum::actingAs($relative);

        $this->postJson('/api/family-space-join-requests/preview', ['token' => $joinToken])
            ->assertOk()->assertJsonPath('data.email_verified', false);
        $this->postJson('/api/family-space-join-requests', ['token' => $joinToken])
            ->assertForbidden();
        $this->postJson('/api/auth/email/verification', ['family_join_token' => $joinToken])
            ->assertOk();

        $verificationUrl = null;
        Notification::assertSentTo($relative, VerifyEmailWithFamilyLink::class, function ($notification) use ($relative, $joinToken, &$verificationUrl) {
            parse_str(parse_url($notification->toMail($relative)->actionUrl, PHP_URL_QUERY), $query);
            $this->assertSame($joinToken, $query['familyJoin'] ?? null);
            $this->assertSame(64, strlen($query['familyJoin'] ?? ''));
            $verificationUrl = $query['emailVerify'] ?? null;

            return $verificationUrl !== null;
        });
        $this->assertNotNull($verificationUrl);
        $this->postJson($verificationUrl)->assertOk();
        $this->assertNotNull($relative->fresh()->email_verified_at);

        $this->postJson('/api/family-space-join-requests/preview', ['token' => $joinToken])
            ->assertOk()->assertJsonPath('data.email_verified', true);
        $requestId = $this->postJson('/api/family-space-join-requests', ['token' => $joinToken])
            ->assertStatus(202)->json('data.id');
        $this->assertDatabaseCount('family_members', 1);

        Sanctum::actingAs($owner);
        $this->getJson('/api/family-spaces/'.$spaceId.'/join-requests')->assertOk()
            ->assertJsonPath('data.0.id', $requestId)
            ->assertJsonPath('data.0.status', 'pending');
        $this->postJson('/api/family-join-requests/'.$requestId.'/approve')
            ->assertOk()->assertJsonPath('data.role', 'viewer');
        $this->assertDatabaseCount('family_members', 2);

        Sanctum::actingAs($relative);
        $this->getJson('/api/child-profiles/'.$childId)->assertOk();
    }

    public function test_verification_link_for_relative_explains_wrong_logged_in_account_without_verifying_either(): void
    {
        $relative = User::factory()->unverified()->create();
        $other = User::factory()->unverified()->create();
        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), ['id' => $relative->id, 'hash' => sha1($relative->email)]);
        Sanctum::actingAs($other);
        $this->postJson($url)->assertForbidden()->assertJsonPath('message', 'Bạn đang đăng nhập tài khoản khác với email cần xác thực. Hãy đổi tài khoản rồi mở lại thư.');
        $this->assertNull($relative->fresh()->email_verified_at);
        $this->assertNull($other->fresh()->email_verified_at);
        Sanctum::actingAs($relative);
        $this->postJson($url)->assertOk();
        $this->assertNotNull($relative->fresh()->email_verified_at);
    }

    public function test_signed_verification_is_bound_to_email_account_and_expiration(): void
    {
        $user = $this->owner();
        $user->forceFill(['email_verified_at' => null])->save();
        $email = (new VerifyEmail)->toMail($user);
        parse_str(parse_url($email->actionUrl, PHP_URL_QUERY), $verificationQuery);
        $this->assertArrayHasKey('emailVerify', $verificationQuery);
        $this->assertSame('/api/auth/email/verify', parse_url($verificationQuery['emailVerify'], PHP_URL_PATH));
        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), ['id' => $user->id, 'hash' => sha1($user->email)]);
        $this->postJson($url)->assertOk();
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->travel(61)->minutes();
        $this->postJson($url)->assertForbidden();
    }

    public function test_password_change_revokes_all_sessions_and_deactivated_account_cannot_login(): void
    {
        $user = User::factory()->create(['password' => 'old-password']);
        $token = $user->createToken('a')->plainTextToken;
        $user->createToken('b');
        $this->withToken($token)->postJson('/api/auth/password', ['current_password' => 'wrong', 'password' => 'new-password', 'password_confirmation' => 'new-password'])->assertUnprocessable();
        $this->withToken($token)->postJson('/api/auth/password', ['current_password' => 'old-password', 'password' => 'new-password', 'password_confirmation' => 'new-password'])->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
        DB::table('users')->where('id', $user->id)->update(['status' => 'deactivated']);
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'new-password'])->assertUnauthorized();
    }

    public function test_co_manager_cannot_accept_ownership_of_a_second_space(): void
    {
        $a = $this->owner();
        $first = $this->child();
        $b = $this->owner();
        $second = $this->child();
        $space = ChildProfile::findOrFail($first)->family_space_id;
        $token = $this->invitation($space, $a->id, $b->email, 'co_manager');
        $this->postJson('/api/family-invitations/preview', ['token' => $token])->assertOk()
            ->assertJsonPath('data.role', 'owner')
            ->assertJsonPath('data.owner_conflict', true)
            ->assertJsonPath('data.can_respond', false);
        $this->postJson('/api/family-invitations/accept', ['token' => $token])->assertConflict();
        $this->assertDatabaseCount('family_members', 2);
        $this->assertNotSame($space, ChildProfile::findOrFail($second)->family_space_id);
    }

    public function test_owner_cannot_join_another_family_but_viewers_can_join_multiple_families(): void
    {
        $firstOwner = $this->owner();
        $firstChild = $this->child();
        $firstSpace = ChildProfile::findOrFail($firstChild)->family_space_id;

        $secondOwner = User::factory()->create();
        Sanctum::actingAs($secondOwner);
        $secondChild = $this->child();
        $secondSpace = ChildProfile::findOrFail($secondChild)->family_space_id;

        $ownerToken = $this->invitation($firstSpace, $firstOwner->id, $secondOwner->email);
        Sanctum::actingAs($secondOwner);
        $this->postJson('/api/family-invitations/preview', ['token' => $ownerToken])->assertOk()
            ->assertJsonPath('data.owner_conflict', true)
            ->assertJsonPath('data.can_respond', false);
        $this->postJson('/api/family-invitations/accept', ['token' => $ownerToken])->assertConflict();
        $this->assertDatabaseCount('family_members', 2);

        $viewer = User::factory()->create();
        $this->member($firstSpace, $viewer);
        $viewerInvite = $this->invitation($secondSpace, $secondOwner->id, $viewer->email);
        Sanctum::actingAs($viewer);
        $this->postJson('/api/family-invitations/accept', ['token' => $viewerInvite])->assertOk()
            ->assertJsonPath('data.role', 'viewer');
        $this->getJson('/api/family-spaces')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_viewer_in_another_family_cannot_accept_co_manager_ownership(): void
    {
        $owner = $this->owner();
        $firstChild = $this->child();
        $firstSpace = ChildProfile::findOrFail($firstChild)->family_space_id;
        $viewer = User::factory()->create();
        $this->member($firstSpace, $viewer);

        $otherOwner = User::factory()->create();
        Sanctum::actingAs($otherOwner);
        $otherChild = $this->child();
        $otherSpace = ChildProfile::findOrFail($otherChild)->family_space_id;
        $token = $this->invitation($otherSpace, $otherOwner->id, $viewer->email, 'co_manager');

        Sanctum::actingAs($viewer);
        $this->postJson('/api/family-invitations/preview', ['token' => $token])->assertOk()
            ->assertJsonPath('data.owner_conflict', true)
            ->assertJsonPath('data.can_respond', false);
        $this->postJson('/api/family-invitations/accept', ['token' => $token])->assertConflict();
        $this->assertDatabaseCount('family_members', 3);
    }

    public function test_family_viewer_cannot_create_an_owner_space_while_joined_to_other_families(): void
    {
        $owner = $this->owner();
        $childId = $this->child();
        $space = ChildProfile::findOrFail($childId)->family_space_id;
        $viewer = User::factory()->create();
        $this->member($space, $viewer);

        Sanctum::actingAs($viewer);
        $this->postJson('/api/child-profiles', $this->payload(), ['X-Operation-Key' => (string) Str::uuid()])->assertConflict();
        $this->assertDatabaseCount('family_spaces', 1);
        $this->assertDatabaseCount('child_profiles', 1);
        $this->assertDatabaseHas('family_members', ['user_id' => $viewer->id, 'family_space_id' => $space, 'role' => 'viewer']);
    }

    public function test_revocation_and_resend_keep_invitation_pending_without_extending_expiration(): void
    {
        $owner = $this->owner();
        $id = $this->child();
        $space = ChildProfile::findOrFail($id)->family_space_id;
        $recipient = User::factory()->create();
        $oldToken = $this->invitation($space, $owner->id, $recipient->email);
        $inv = DB::table('family_invitations')->first();
        $this->postJson('/api/family-invitations/'.$inv->id.'/resend')->assertOk();
        $fresh = DB::table('family_invitations')->find($inv->id);
        $this->assertSame($inv->expires_at, $fresh->expires_at);
        $this->assertNotSame($inv->token_hash, $fresh->token_hash);
        $this->postJson('/api/family-invitations/'.$inv->id.'/revoke')->assertOk();
        $this->assertDatabaseCount('family_members', 1);
        Sanctum::actingAs($recipient);
        $this->postJson('/api/family-invitations/accept', ['token' => $oldToken])->assertForbidden();
    }

    public function test_failed_invitation_resend_keeps_the_previous_link_valid(): void
    {
        $owner = $this->owner();
        $childId = $this->child();
        $spaceId = ChildProfile::findOrFail($childId)->family_space_id;
        $recipient = User::factory()->create();
        $oldToken = $this->invitation($spaceId, $owner->id, $recipient->email);
        $invitation = DB::table('family_invitations')->first();

        Mail::shouldReceive('raw')->once()->andThrow(new \RuntimeException('SMTP unavailable'));
        $this->postJson('/api/family-invitations/'.$invitation->id.'/resend')
            ->assertOk()->assertJsonPath('data.delivery', 'failed');

        $this->assertDatabaseHas('family_invitations', [
            'id' => $invitation->id,
            'status' => 'pending',
            'token_hash' => hash('sha256', $oldToken),
        ]);
        Sanctum::actingAs($recipient);
        $this->postJson('/api/family-invitations/accept', ['token' => $oldToken])
            ->assertOk()->assertJsonPath('data.status', 'accepted');
        $this->assertDatabaseHas('family_members', [
            'family_space_id' => $spaceId,
            'user_id' => $recipient->id,
            'role' => 'viewer',
        ]);
    }

    public function test_unverified_owner_cannot_send_or_resend_email_but_can_revoke(): void
    {
        $owner = $this->owner();
        $childId = $this->child();
        $spaceId = ChildProfile::findOrFail($childId)->family_space_id;
        $recipient = User::factory()->create();
        $this->invitation($spaceId, $owner->id, $recipient->email);
        $invitationId = DB::table('family_invitations')->value('id');
        $owner->forceFill(['email_verified_at' => null])->save();
        Mail::shouldReceive('raw')->never();

        $this->postJson('/api/family-spaces/'.$spaceId.'/invitations', [
            'email' => 'another-relative@example.test',
            'invitation_type' => 'family_viewer',
            'confirmed' => true,
        ])->assertForbidden();
        $this->postJson('/api/family-invitations/'.$invitationId.'/resend')->assertForbidden();
        $this->assertDatabaseCount('family_invitations', 1);
        $this->postJson('/api/family-invitations/'.$invitationId.'/revoke')->assertOk();
        $this->assertDatabaseHas('family_invitations', ['id' => $invitationId, 'status' => 'revoked']);
    }

    public function test_revoked_current_invitation_cannot_be_accepted_or_resent(): void
    {
        $owner = $this->owner();
        $child = $this->child();
        $space = ChildProfile::findOrFail($child)->family_space_id;
        $recipient = User::factory()->create();
        $token = $this->invitation($space, $owner->id, $recipient->email);
        $id = DB::table('family_invitations')->value('id');
        $this->postJson('/api/family-invitations/'.$id.'/revoke')->assertOk();
        $this->postJson('/api/family-invitations/'.$id.'/resend')->assertConflict();
        Sanctum::actingAs($recipient);
        $this->postJson('/api/family-invitations/preview', ['token' => $token])->assertOk()
            ->assertJsonPath('data.status', 'revoked')->assertJsonPath('data.can_respond', false);
        $this->postJson('/api/family-invitations/accept', ['token' => $token])->assertConflict();
        $this->assertDatabaseCount('family_members', 1);
    }

    public function test_email_invited_member_can_reopen_family_link_without_another_join_request(): void
    {
        $owner = $this->owner();
        $child = $this->child();
        $space = ChildProfile::findOrFail($child)->family_space_id;
        $link = $this->postJson('/api/family-spaces/'.$space.'/join-links')->assertCreated()->json('data');
        $viewer = User::factory()->create();
        $this->member($space, $viewer);
        Sanctum::actingAs($viewer);
        $this->postJson('/api/family-space-join-requests/preview', ['token' => $link['token']])->assertOk()
            ->assertJsonPath('data.already_member', true)->assertJsonPath('data.status', 'approved');
        $this->postJson('/api/family-space-join-requests', ['token' => $link['token']])->assertOk()
            ->assertJsonPath('data.already_member', true);
        $this->assertDatabaseCount('family_join_requests', 0);
        $this->assertDatabaseCount('family_members', 2);
        Sanctum::actingAs($owner);
        $this->deleteJson('/api/family-join-links/'.$link['id'])->assertOk();
        Sanctum::actingAs($viewer);
        $this->postJson('/api/family-space-join-requests/preview', ['token' => $link['token']])->assertStatus(410);
        $this->postJson('/api/family-space-join-requests', ['token' => $link['token']])->assertStatus(410);
    }

    public function test_accepting_direct_invitation_closes_an_existing_pending_join_request(): void
    {
        $owner = $this->owner();
        $child = $this->child();
        $space = ChildProfile::findOrFail($child)->family_space_id;
        $link = $this->postJson('/api/family-spaces/'.$space.'/join-links')->assertCreated()->json('data');
        $relative = User::factory()->create();
        Sanctum::actingAs($relative);
        $requestId = $this->postJson('/api/family-space-join-requests', ['token' => $link['token']])
            ->assertStatus(202)->json('data.id');

        Sanctum::actingAs($owner);
        $invitationToken = $this->invitation($space, $owner->id, $relative->email);
        Sanctum::actingAs($relative);
        $this->postJson('/api/family-invitations/accept', ['token' => $invitationToken])
            ->assertOk()->assertJsonPath('data.status', 'accepted');

        $this->getJson('/api/family-space-join-requests/'.$requestId)
            ->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertDatabaseCount('family_members', 2);
        Sanctum::actingAs($owner);
        $this->postJson('/api/family-join-requests/'.$requestId.'/approve')->assertConflict();
    }

    public function test_family_space_link_requires_owner_approval_before_creating_viewer_membership(): void
    {
        $owner = $this->owner();
        $childId = $this->child();
        $spaceId = ChildProfile::findOrFail($childId)->family_space_id;
        $link = $this->postJson('/api/family-spaces/'.$spaceId.'/join-links')->assertCreated()
            ->assertJsonPath('data.status', 'active')->assertJsonPath('data.role', 'viewer');
        $linkId = $link->json('data.id');
        $token = $link->json('data.token');
        $this->assertNotSame($token, DB::table('family_space_links')->where('id', $linkId)->value('token_hash'));

        $requester = User::factory()->create();
        Sanctum::actingAs($requester);
        $request = $this->postJson('/api/family-space-join-requests', ['token' => $token])
            ->assertStatus(202)->assertJsonPath('data.status', 'pending');
        $requestId = $request->json('data.id');
        $this->assertDatabaseCount('family_members', 1);
        $this->getJson('/api/family-space-join-requests/'.$requestId)->assertOk()->assertJsonPath('data.status', 'pending');

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/family-spaces/'.$spaceId.'/join-requests')->assertForbidden();
        $this->postJson('/api/family-join-requests/'.$requestId.'/approve')->assertForbidden();

        Sanctum::actingAs($owner);
        $this->getJson('/api/family-spaces/'.$spaceId.'/join-requests')->assertOk()
            ->assertJsonPath('data.0.status', 'pending')->assertJsonPath('data.0.email', $requester->email);
        $this->postJson('/api/family-join-requests/'.$requestId.'/approve')->assertOk()
            ->assertJsonPath('data.status', 'approved')->assertJsonPath('data.role', 'viewer');
        $this->assertDatabaseCount('family_members', 2);
        $this->assertDatabaseHas('family_members', ['family_space_id' => $spaceId, 'user_id' => $requester->id, 'role' => 'viewer']);
        $this->postJson('/api/family-join-requests/'.$requestId.'/approve')->assertConflict();

        Sanctum::actingAs($requester);
        $this->getJson('/api/family-space-join-requests/'.$requestId)->assertOk()->assertJsonPath('data.status', 'approved');
    }

    public function test_family_join_link_rejects_unverified_expired_and_revoked_requests_and_owner_can_decline(): void
    {
        $owner = $this->owner();
        $childId = $this->child();
        $spaceId = ChildProfile::findOrFail($childId)->family_space_id;
        $link = $this->postJson('/api/family-spaces/'.$spaceId.'/join-links')->assertCreated();
        $linkId = $link->json('data.id');
        $token = $link->json('data.token');

        $unverified = User::factory()->unverified()->create();
        Sanctum::actingAs($unverified);
        $this->postJson('/api/family-space-join-requests', ['token' => $token])->assertForbidden();

        $requester = User::factory()->create();
        Sanctum::actingAs($requester);
        $requestId = $this->postJson('/api/family-space-join-requests', ['token' => $token])
            ->assertStatus(202)->json('data.id');
        Sanctum::actingAs($owner);
        $this->postJson('/api/family-join-requests/'.$requestId.'/decline')->assertOk();
        $this->assertDatabaseCount('family_members', 1);
        Sanctum::actingAs($requester);
        $this->getJson('/api/family-space-join-requests/'.$requestId)->assertOk()->assertJsonPath('data.status', 'declined');
        $this->postJson('/api/family-space-join-requests', ['token' => $token])->assertStatus(202)->assertJsonPath('data.id', $requestId);

        Sanctum::actingAs($owner);
        $this->deleteJson('/api/family-join-links/'.$linkId)->assertOk();
        $this->getJson('/api/family-spaces/'.$spaceId.'/join-links')->assertOk()->assertJsonPath('data.0.status', 'revoked');
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/family-space-join-requests', ['token' => $token])->assertStatus(410);

        Sanctum::actingAs($owner);
        $expired = $this->postJson('/api/family-spaces/'.$spaceId.'/join-links')->assertCreated();
        DB::table('family_space_links')->where('id', $expired->json('data.id'))->update(['expires_at' => now()->subSecond()]);
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/family-space-join-requests', ['token' => $expired->json('data.token')])->assertStatus(410);
    }

    public function test_health_stale_version_and_birth_recalculation_preserve_real_dates(): void
    {
        $this->owner();
        $id = $this->child();
        $data = ['child_profile_id' => $id, 'measured_at' => '2026-10-01', 'height_cm' => 70, 'weight_kg' => 8.5, 'measurement_method' => 'lying', 'expected_data_revision' => 1];
        $record = $this->postJson('/api/health-records', $data)->assertCreated()->json('data.id');
        $data['expected_version'] = 0;
        $this->patchJson('/api/health-records/'.$record, $data)->assertConflict();
        $schedule = DB::table('vaccination_schedules')->where('child_profile_id', $id)->value('id');
        DB::table('vaccination_schedules')->where('id', $schedule)->update(['appointment_date' => '2026-11-01', 'appointment_source' => 'Test facility']);
        $this->putJson('/api/child-profiles/'.$id, ['name' => 'An', 'date_of_birth' => '2026-02-01', 'gender' => 'female', 'expected_data_revision' => 2])->assertOk();
        $this->assertDatabaseHas('health_records', ['id' => $record, 'measured_at' => '2026-10-01', 'age_days' => 242, 'version' => 1]);
        $this->assertDatabaseHas('vaccination_schedules', ['id' => $schedule, 'appointment_date' => '2026-11-01', 'due_date' => '2026-03-01']);
    }

    public function test_health_record_update_and_delete_require_current_versions_and_confirmation(): void
    {
        $this->owner();
        $id = $this->child();
        $data = [
            'child_profile_id' => $id,
            'measured_at' => '2026-10-01',
            'height_cm' => 70,
            'weight_kg' => 8.5,
            'measurement_method' => 'lying',
            'expected_data_revision' => 1,
        ];
        $record = $this->postJson('/api/health-records', $data)
            ->assertCreated()
            ->json('data.id');

        $update = [
            'measured_at' => '2026-10-02',
            'height_cm' => 70.5,
            'weight_kg' => 8.6,
            'measurement_method' => 'standing',
            'expected_version' => 0,
            'expected_data_revision' => 2,
        ];
        $this->patchJson('/api/health-records/'.$record, $update)
            ->assertOk()
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.measured_at', '2026-10-02');
        $this->patchJson('/api/health-records/'.$record, $update)
            ->assertConflict();

        $delete = [
            'expected_version' => 1,
            'expected_data_revision' => 3,
            'confirmed' => false,
        ];
        $this->deleteJson('/api/health-records/'.$record, $delete)
            ->assertUnprocessable();
        $delete['confirmed'] = true;
        $this->deleteJson('/api/health-records/'.$record, $delete)
            ->assertOk();
        $this->assertDatabaseMissing('health_records', ['id' => $record]);
    }

    public function test_vaccination_relink_is_audited_and_rejects_cross_child_and_stale_versions(): void
    {
        $this->owner();
        $id = $this->child();
        $other = $this->child();
        $schedules = DB::table('vaccination_schedules')->where('child_profile_id', $id)->pluck('id');
        $wrong = DB::table('vaccination_schedules')->where('child_profile_id', $other)->value('id');
        $data = ['vaccine_name' => 'Test parent report', 'date_precision' => 'exact', 'administered_date' => '2026-03-01', 'vaccination_schedule_id' => $wrong, 'source' => 'parent_report', 'expected_data_revision' => 1];
        $this->postJson('/api/child-profiles/'.$id.'/vaccination-records', $data)->assertUnprocessable();
        $data['vaccination_schedule_id'] = $schedules[0];
        $record = $this->postJson('/api/child-profiles/'.$id.'/vaccination-records', $data)->assertCreated()->json('data.id');
        $data += ['action' => 'correct', 'reason' => 'Correct schedule link', 'expected_version' => 0];
        $data['vaccination_schedule_id'] = $schedules[1];
        $this->patchJson('/api/vaccination-records/'.$record, $data)->assertConflict();
        $data['expected_data_revision'] = 2;
        $this->patchJson('/api/vaccination-records/'.$record, $data)->assertOk()->assertJsonPath('data.version', 1);
        $this->assertDatabaseHas('vaccination_schedules', ['id' => $schedules[0], 'status' => 'unrecorded']);
        $this->assertDatabaseHas('vaccination_schedules', ['id' => $schedules[1], 'status' => 'recorded']);
        $this->assertDatabaseCount('vaccination_record_changes', 2);
    }

    public function test_catalog_import_requires_review_and_rejects_duplicate_versions_atomically(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'babi-catalog-');
        try {
            $previousId = DB::table('vaccine_templates')->where('vaccine_code', 'TEST-AGE')->value('id');
            file_put_contents($file, json_encode(['schedule_version' => 'IMPORT-TEST', 'program' => 'expanded', 'effective_until' => '2026-12-31', 'templates' => [['vaccine_code' => 'TEST-ONLY', 'name' => 'Synthetic fixture', 'dose_number' => 1, 'disease' => 'TEST ONLY', 'timing_rule' => 'clinical', 'previous_template_id' => $previousId, 'min_interval_days' => 28, 'source_url' => 'https://example.invalid/test-only']]], JSON_THROW_ON_ERROR));
            $this->artisan('babi:import-vaccines', ['file' => $file])->assertFailed();
            $this->artisan('babi:import-vaccines', ['file' => $file, '--reviewed' => true])->assertSuccessful();
            $this->artisan('babi:import-vaccines', ['file' => $file, '--reviewed' => true])->assertFailed();
            $this->assertSame(1, DB::table('vaccine_templates')->where('schedule_version', 'IMPORT-TEST')->count());
            $this->assertDatabaseHas('vaccine_templates', ['schedule_version' => 'IMPORT-TEST', 'previous_template_id' => $previousId, 'min_interval_days' => 28, 'effective_until' => '2026-12-31']);
        } finally {
            unlink($file);
        }
    }

    public function test_catalog_import_rejects_case_insensitive_duplicate_vaccine_codes_before_database_insert(): void
    {
        $catalog = [
            'schedule_version' => 'CASE-DUPLICATE-TEST',
            'program' => 'expanded',
            'templates' => [
                ['vaccine_code' => 'Case-Test', 'name' => 'Dose one', 'dose_number' => 1, 'disease' => 'TEST ONLY', 'timing_rule' => 'clinical', 'source_url' => 'https://example.invalid/test-only'],
                ['vaccine_code' => ' case-test ', 'name' => 'Duplicate dose one', 'dose_number' => 1, 'disease' => 'TEST ONLY', 'timing_rule' => 'clinical', 'source_url' => 'https://example.invalid/test-only'],
            ],
        ];

        try {
            app(VaccineCatalogImporter::class)->import($catalog);
            $this->fail('Expected duplicate case-insensitive vaccine codes to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('templates.1.dose_number', $exception->errors());
        }

        $this->assertDatabaseMissing('vaccine_templates', ['schedule_version' => 'CASE-DUPLICATE-TEST']);

        app(VaccineCatalogImporter::class)->import([
            'schedule_version' => ' TRIM-TEST ',
            'program' => 'expanded',
            'templates' => [[
                'vaccine_code' => ' Trim-Code ', 'name' => 'Dose one', 'dose_number' => 1,
                'disease' => 'TEST ONLY', 'timing_rule' => 'clinical',
                'source_url' => 'https://example.invalid/test-only',
            ]],
        ]);
        $this->assertDatabaseHas('vaccine_templates', [
            'schedule_version' => 'TRIM-TEST', 'vaccine_code' => 'Trim-Code',
        ]);
    }

    public function test_expired_vaccine_catalog_is_rejected_and_cannot_generate_new_schedule(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'babi-expired-catalog-');
        try {
            file_put_contents($file, json_encode([
                'schedule_version' => 'EXPIRED-TEST',
                'program' => 'expanded',
                'effective_until' => '2026-10-16',
                'templates' => [['vaccine_code' => 'EXPIRED-ONLY', 'name' => 'Expired fixture', 'dose_number' => 1, 'disease' => 'TEST ONLY', 'timing_rule' => 'age', 'age_value' => 1, 'age_unit' => 'month', 'source_url' => 'https://example.invalid/test-only']],
            ], JSON_THROW_ON_ERROR));
            $this->artisan('babi:import-vaccines', ['file' => $file, '--reviewed' => true])->assertFailed();
            $this->assertDatabaseMissing('vaccine_templates', ['schedule_version' => 'EXPIRED-TEST']);
        } finally {
            unlink($file);
        }

        $this->owner();
        DB::table('vaccine_templates')->insert([
            'vaccine_code' => 'EXPIRED-LEGACY', 'name' => 'Expired legacy fixture', 'dose_number' => 1,
            'disease' => 'TEST ONLY', 'program' => 'expanded', 'schedule_version' => 'EXPIRED-LEGACY',
            'effective_until' => '2026-10-16', 'timing_rule' => 'age', 'age_value' => 1,
            'age_unit' => 'month', 'source_url' => 'https://example.invalid/test-only',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        config(['babi.vaccine_catalog_version' => 'EXPIRED-LEGACY']);
        $this->getJson('/api/app-config')->assertOk()->assertJsonPath('data.vaccine_catalog_ready', false);
        $this->postJson('/api/child-profiles', $this->payload(), ['X-Operation-Key' => (string) Str::uuid()])
            ->assertCreated()->assertJsonPath('meta.vaccine_schedule_count', 0)->assertJsonPath('meta.vaccine_catalog_ready', false);
        $this->assertDatabaseCount('vaccination_schedules', 0);
        $this->artisan('babi:populate-missing-vaccination-schedules')->assertFailed();
        $this->assertDatabaseCount('vaccination_schedules', 0);
    }

    public function test_pagination_invalid_input_and_removed_membership_are_rejected(): void
    {
        $this->owner();
        $id = $this->child();
        $child = ChildProfile::findOrFail($id);
        $viewer = User::factory()->create();
        $membership = $this->member($child->family_space_id, $viewer);
        Sanctum::actingAs($viewer);
        $this->getJson('/api/child-profiles/'.$id.'/vaccinations?per_page=101')->assertUnprocessable();
        $this->getJson('/api/child-profiles/'.$id.'/health-records?page=0')->assertUnprocessable();
        DB::table('family_members')->where('id', $membership)->delete();
        $this->getJson('/api/child-profiles/'.$id.'/vaccinations')->assertForbidden();
    }

    public function test_admin_can_deactivate_user_but_cannot_remove_last_active_owner(): void
    {
        $owner = $this->owner();
        $childId = $this->child();
        $spaceId = ChildProfile::findOrFail($childId)->family_space_id;
        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($owner);
        $this->patchJson('/api/admin/users/'.$owner->id.'/status', ['status' => 'deactivated'])->assertForbidden();
        Sanctum::actingAs($admin);

        $this->patchJson('/api/admin/users/'.$owner->id.'/status', ['status' => 'deactivated'])->assertConflict();
        $this->assertSame('active', $owner->fresh()->status);

        $coOwner = User::factory()->create();
        $this->member($spaceId, $coOwner, 'owner');
        $owner->createToken('admin-status-test');
        DB::table('device_tokens')->insert([
            'user_id' => $owner->id, 'fcm_token' => Crypt::encryptString('admin-disable-test-device-token'),
            'token_hash' => hash('sha256', 'admin-disable-test-device-token'), 'device_type' => 'android',
            'installation_id' => (string) Str::uuid(), 'last_used_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $response = $this->patchJson('/api/admin/users/'.$owner->id.'/status', [
            'status' => 'deactivated', 'is_admin' => true,
        ])->assertOk()->assertJsonPath('data.status', 'deactivated');
        $this->assertArrayNotHasKey('is_admin', $response->json('data'));
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $owner->id]);
        $this->assertDatabaseMissing('device_tokens', ['user_id' => $owner->id]);
        $this->assertSame('active', $coOwner->fresh()->status);
        $this->postJson('/api/auth/login', ['email' => $owner->email, 'password' => 'password'])->assertUnauthorized();
    }

    public function test_admin_can_select_an_effective_catalog_and_regular_users_cannot(): void
    {
        $owner = $this->owner();
        $this->getJson('/api/admin/vaccine-catalogs')->assertForbidden();
        $this->putJson('/api/admin/vaccine-catalogs/active', ['schedule_version' => 'TEST-ONLY'])->assertForbidden();

        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin);
        $this->getJson('/api/admin/vaccine-catalogs')->assertOk()
            ->assertJsonPath('data.0.schedule_version', 'TEST-ONLY')
            ->assertJsonMissingPath('data.0.source_url');
        DB::table('vaccine_templates')->insert([
            'vaccine_code' => 'ADMIN-NEW', 'name' => 'Synthetic admin fixture', 'dose_number' => 1,
            'disease' => 'TEST ONLY', 'program' => 'expanded', 'schedule_version' => 'ADMIN-NEW',
            'effective_until' => '2027-10-31', 'timing_rule' => 'age', 'age_value' => 2,
            'age_unit' => 'month', 'source_url' => 'https://example.invalid/test-only',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('vaccine_templates')->insert([
            'vaccine_code' => 'ADMIN-EXPIRED', 'name' => 'Expired admin fixture', 'dose_number' => 1,
            'disease' => 'TEST ONLY', 'program' => 'expanded', 'schedule_version' => 'ADMIN-EXPIRED',
            'effective_until' => '2026-10-16', 'timing_rule' => 'age', 'age_value' => 2,
            'age_unit' => 'month', 'source_url' => 'https://example.invalid/test-only',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->putJson('/api/admin/vaccine-catalogs/active', ['schedule_version' => 'ADMIN-EXPIRED'])->assertUnprocessable();
        $this->putJson('/api/admin/vaccine-catalogs/active', ['schedule_version' => 'ADMIN-NEW'])
            ->assertOk()->assertJsonPath('data.schedule_version', 'ADMIN-NEW')->assertJsonPath('data.vaccine_catalog_ready', true);
        $this->getJson('/api/app-config')->assertOk()
            ->assertJsonPath('data.vaccine_catalog_version', 'ADMIN-NEW')
            ->assertJsonPath('data.vaccine_catalog_ready', true);
        $this->assertDatabaseHas('vaccine_catalog_settings', [
            'setting_key' => 'expanded', 'schedule_version' => 'ADMIN-NEW', 'updated_by' => $admin->id,
        ]);

        Sanctum::actingAs($owner);
        $childId = $this->child();
        $this->assertDatabaseCount('vaccination_schedules', 1);
        $this->assertDatabaseHas('vaccination_schedules', [
            'child_profile_id' => $childId,
            'vaccine_template_id' => DB::table('vaccine_templates')->where('schedule_version', 'ADMIN-NEW')->value('id'),
        ]);
    }

    public function test_console_admin_bootstrap_only_grants_verified_active_accounts(): void
    {
        $unverified = User::factory()->unverified()->create();
        $inactive = User::factory()->create();
        DB::table('users')->where('id', $inactive->id)->update(['status' => 'deactivated']);
        $eligible = User::factory()->create();

        $this->assertSame(1, Artisan::call('babi:grant-admin', ['email' => $unverified->email, '--force' => true]));
        $this->assertSame(1, Artisan::call('babi:grant-admin', ['email' => $inactive->email, '--force' => true]));
        $this->assertSame(1, Artisan::call('babi:grant-admin', ['email' => 'missing@example.test', '--force' => true]));
        $this->assertFalse($unverified->fresh()->is_admin);
        $this->assertFalse($inactive->fresh()->is_admin);

        $this->assertSame(0, Artisan::call('babi:grant-admin', ['email' => strtoupper($eligible->email), '--force' => true]));
        $this->assertTrue($eligible->fresh()->is_admin);
        $this->assertSame(0, Artisan::call('babi:grant-admin', ['email' => $eligible->email, '--force' => true]));
    }

    public function test_console_admin_revocation_preserves_last_active_admin(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $regular = User::factory()->create();

        $this->assertSame(1, Artisan::call('babi:revoke-admin', ['email' => $admin->email, '--force' => true]));
        $this->assertTrue($admin->fresh()->is_admin);

        $anotherAdmin = User::factory()->create(['is_admin' => true]);
        $this->assertSame(0, Artisan::call('babi:revoke-admin', ['email' => $admin->email, '--force' => true]));
        $this->assertFalse($admin->fresh()->is_admin);
        $this->assertTrue($anotherAdmin->fresh()->is_admin);

        $this->assertSame(0, Artisan::call('babi:revoke-admin', ['email' => $regular->email, '--force' => true]));
        $this->assertFalse($regular->fresh()->is_admin);
    }

    public function test_admin_can_import_a_reviewed_immutable_catalog_without_activating_it(): void
    {
        $owner = $this->owner();
        Sanctum::actingAs($owner);
        $payload = [
            'reviewed' => true,
            'schedule_version' => 'ADMIN-IMPORTED',
            'program' => 'expanded',
            'effective_until' => '2027-10-31',
            'templates' => [[
                'vaccine_code' => 'IMPORT-ONLY', 'name' => 'Synthetic import fixture', 'dose_number' => 1,
                'disease' => 'TEST ONLY', 'timing_rule' => 'age', 'age_value' => 2, 'age_unit' => 'month',
                'source_url' => 'https://example.invalid/test-only',
            ]],
        ];
        $this->postJson('/api/admin/vaccine-catalogs', $payload)->assertForbidden();
        $this->assertDatabaseMissing('vaccine_templates', ['schedule_version' => 'ADMIN-IMPORTED']);

        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin);
        $unreviewed = $payload;
        $unreviewed['reviewed'] = false;
        $this->postJson('/api/admin/vaccine-catalogs', $unreviewed)->assertUnprocessable();
        $this->postJson('/api/admin/vaccine-catalogs', $payload)
            ->assertCreated()
            ->assertJsonPath('data.schedule_version', 'ADMIN-IMPORTED')
            ->assertJsonPath('data.template_count', 1)
            ->assertJsonMissingPath('data.source_url');
        $this->assertDatabaseHas('vaccine_templates', [
            'schedule_version' => 'ADMIN-IMPORTED', 'program' => 'expanded', 'is_active' => 1,
        ]);
        $this->assertDatabaseMissing('vaccine_catalog_settings', ['schedule_version' => 'ADMIN-IMPORTED']);
        $this->putJson('/api/admin/vaccine-catalogs/active', ['schedule_version' => 'ADMIN-IMPORTED'])
            ->assertOk()
            ->assertJsonPath('data.schedule_version', 'ADMIN-IMPORTED')
            ->assertJsonPath('data.vaccine_catalog_ready', true);
        $this->assertDatabaseHas('vaccine_catalog_settings', [
            'setting_key' => 'expanded', 'schedule_version' => 'ADMIN-IMPORTED', 'updated_by' => $admin->id,
        ]);

        $openEnded = $payload;
        $openEnded['schedule_version'] = 'ADMIN-OPEN-ENDED';
        unset($openEnded['effective_until']);
        $this->postJson('/api/admin/vaccine-catalogs', $openEnded)
            ->assertCreated()
            ->assertJsonPath('data.effective_until', null);
        $this->assertDatabaseHas('vaccine_templates', ['schedule_version' => 'ADMIN-OPEN-ENDED', 'effective_until' => null]);
        $this->putJson('/api/admin/vaccine-catalogs/active', ['schedule_version' => 'ADMIN-OPEN-ENDED'])
            ->assertOk()
            ->assertJsonPath('data.schedule_version', 'ADMIN-OPEN-ENDED')
            ->assertJsonPath('data.vaccine_catalog_ready', true);

        $this->postJson('/api/admin/vaccine-catalogs', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('schedule_version');

        $expired = $payload;
        $expired['schedule_version'] = 'ADMIN-EXPIRED-IMPORT';
        $expired['effective_until'] = '2026-10-16';
        $this->postJson('/api/admin/vaccine-catalogs', $expired)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('effective_until');
        $this->assertDatabaseMissing('vaccine_templates', ['schedule_version' => 'ADMIN-EXPIRED-IMPORT']);
    }

    public function test_repository_vaccine_catalog_draft_imports_without_claiming_medical_review_or_activation(): void
    {
        $path = database_path('catalogs/vietnam-epi-52-2025.json');
        $catalog = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $existingTemplateCount = DB::table('vaccine_templates')->count();

        $result = app(VaccineCatalogImporter::class)->import($catalog);

        $this->assertSame('VN-EPI-52-2025', $result['schedule_version']);
        $this->assertSame(18, $result['template_count']);
        $this->assertNull($result['effective_until']);
        $this->assertDatabaseCount('vaccine_templates', $existingTemplateCount + 18);
        $this->assertDatabaseMissing('vaccine_catalog_settings', ['setting_key' => 'expanded']);
        $jeDoseThree = DB::table('vaccine_templates')
            ->where('schedule_version', 'VN-EPI-52-2025')
            ->where('vaccine_code', 'JEV')->where('dose_number', 3)->first();
        $this->assertStringContainsString('mũi 3 một năm sau mũi 1', $jeDoseThree->timing_note);
        $this->assertStringContainsString('cách mũi 2 ít nhất một tháng', $jeDoseThree->timing_note);
        $this->assertSame('https://emohbackup.moh.gov.vn/publish/attach/getfile/413589', $jeDoseThree->source_url);
    }
}
