<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\FcmClient;
use App\Services\FcmDeliveryUnknownException;
use App\Services\MilestoneReminderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MilestoneReminderTest extends TestCase
{
    use RefreshDatabase;

    private function familyWithChild(User $owner, string $birthDate = '2026-01-31'): int
    {
        $spaceId = DB::table('family_spaces')->insertGetId([
            'owner_user_id' => $owner->id, 'name' => 'Babi test', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('family_members')->insert([
            'family_space_id' => $spaceId, 'user_id' => $owner->id, 'role' => 'owner', 'joined_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $childId = DB::table('child_profiles')->insertGetId([
            'user_id' => $owner->id, 'family_space_id' => $spaceId, 'name' => 'Bé test', 'date_of_birth' => $birthDate,
            'gender' => 'female', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('child_data_consents')->insert([
            'child_profile_id' => $childId, 'given_by' => $owner->id, 'purpose' => 'storage',
            'policy_version' => config('babi.policy_version'), 'representative_confirmed' => true,
            'granted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $childId;
    }

    private function templates(): void
    {
        foreach ([['One month', 1, false], ['Birthday', 12, true]] as [$name, $age, $recurring]) {
            DB::table('milestone_templates')->insert([
                'name' => $name, 'age_months' => $age, 'description' => null, 'icon_code' => 'cake',
                'is_recurring' => $recurring, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function test_milestone_generation_is_idempotent_clamps_month_end_and_stops_at_sixty_months(): void
    {
        $this->travelTo(now()->setDate(2026, 1, 1)->startOfDay());
        $this->templates();
        $owner = User::factory()->create();
        $childId = $this->familyWithChild($owner);
        $service = app(MilestoneReminderService::class);

        $service->syncForChild($childId, '2026-01-31');
        $service->syncForChild($childId, '2026-01-31');

        $dates = DB::table('milestone_reminders')->orderBy('reminder_date')->pluck('reminder_date')->all();
        $this->assertSame([
            '2026-02-28', '2027-01-31', '2028-01-31', '2029-01-31', '2030-01-31', '2031-01-31',
        ], $dates);
        $this->assertDatabaseCount('milestone_reminders', 6);
    }

    public function test_birth_date_change_cancels_obsolete_reminders_and_restores_still_valid_dates(): void
    {
        $this->travelTo(now()->setDate(2026, 1, 1)->startOfDay());
        $this->templates();
        $owner = User::factory()->create();
        $childId = $this->familyWithChild($owner, '2026-01-28');
        $service = app(MilestoneReminderService::class);
        $service->syncForChild($childId, '2026-01-28');

        $service->syncForChild($childId, '2026-01-25');
        $this->assertNotNull(DB::table('milestone_reminders')->where('child_profile_id', $childId)->where('reminder_date', '2026-02-28')->value('cancelled_at'));
        $this->assertDatabaseHas('milestone_reminders', ['child_profile_id' => $childId, 'reminder_date' => '2026-02-25']);
        $service->syncForChild($childId, '2026-01-28');

        $this->assertNull(DB::table('milestone_reminders')->where('child_profile_id', $childId)->where('reminder_date', '2026-02-28')->value('cancelled_at'));
        $this->assertNotNull(DB::table('milestone_reminders')->where('child_profile_id', $childId)->where('reminder_date', '2026-02-25')->value('cancelled_at'));
    }

    public function test_february_29_birthday_anniversaries_clamp_to_february_28_except_leap_years(): void
    {
        $this->travelTo(now()->setDate(2024, 2, 27)->startOfDay());
        DB::table('milestone_templates')->insert([
            'name' => 'Birthday', 'age_months' => 12, 'description' => null, 'icon_code' => 'cake',
            'is_recurring' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $owner = User::factory()->create();
        $childId = $this->familyWithChild($owner, '2024-02-29');

        app(MilestoneReminderService::class)->syncForChild($childId, '2024-02-29');

        $this->assertSame(['2025-02-28', '2026-02-28', '2027-02-28', '2028-02-29', '2029-02-28'],
            DB::table('milestone_reminders')->orderBy('reminder_date')->pluck('reminder_date')->all());
    }

    public function test_sync_command_is_idempotent_and_skips_profiles_without_active_storage_consent(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 1)->startOfDay());
        $this->templates();
        $owner = User::factory()->create();
        $activeChild = $this->familyWithChild($owner, '2026-10-31');
        $withdrawnOwner = User::factory()->create();
        $withdrawnChild = $this->familyWithChild($withdrawnOwner, '2026-10-31');
        DB::table('child_data_consents')->where('child_profile_id', $withdrawnChild)->update(['withdrawn_at' => now()]);

        Artisan::call('babi:sync-milestone-reminders');
        $firstCount = DB::table('milestone_reminders')->count();
        Artisan::call('babi:sync-milestone-reminders');

        $this->assertGreaterThan(0, $firstCount);
        $this->assertSame($firstCount, DB::table('milestone_reminders')->count());
        $this->assertDatabaseMissing('milestone_reminders', ['child_profile_id' => $withdrawnChild]);
        $this->assertDatabaseHas('milestone_reminders', ['child_profile_id' => $activeChild]);
    }

    public function test_only_owner_can_complete_a_milestone_and_completion_cancels_unsent_deliveries(): void
    {
        $this->templates();
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $childId = $this->familyWithChild($owner, '2026-11-20');
        $spaceId = DB::table('child_profiles')->where('id', $childId)->value('family_space_id');
        DB::table('family_members')->insert([
            'family_space_id' => $spaceId, 'user_id' => $viewer->id, 'role' => 'viewer', 'joined_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        app(MilestoneReminderService::class)->syncForChild($childId, '2026-11-20');
        $reminderId = DB::table('milestone_reminders')->value('id');
        $deviceId = DB::table('device_tokens')->insertGetId([
            'user_id' => $owner->id, 'fcm_token' => 'encrypted-token', 'token_hash' => hash('sha256', 'device'),
            'device_type' => 'ios', 'installation_id' => (string) Str::uuid(), 'last_used_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('milestone_notification_deliveries')->insert([
            'milestone_reminder_id' => $reminderId, 'days_before' => 7, 'user_id' => $owner->id,
            'device_token_id' => $deviceId, 'device_key' => hash('sha256', 'device'), 'created_at' => now(), 'updated_at' => now(),
        ]);

        Sanctum::actingAs($viewer);
        $this->postJson('/api/milestone-reminders/'.$reminderId.'/complete')->assertForbidden();
        Sanctum::actingAs($owner);
        $this->postJson('/api/milestone-reminders/'.$reminderId.'/complete')->assertOk()->assertJsonPath('data.status', 'completed');
        $this->assertDatabaseHas('milestone_notification_deliveries', ['milestone_reminder_id' => $reminderId, 'status' => 'skipped']);
    }

    public function test_seven_day_and_one_day_milestone_pushes_are_independent(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 1)->startOfDay());
        $this->templates();
        $owner = User::factory()->create();
        $childId = $this->familyWithChild($owner, '2026-10-08');
        $viewer = User::factory()->create();
        $spaceId = DB::table('child_profiles')->where('id', $childId)->value('family_space_id');
        DB::table('family_members')->insert([
            'family_space_id' => $spaceId, 'user_id' => $viewer->id, 'role' => 'viewer', 'joined_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        app(MilestoneReminderService::class)->syncForChild($childId, '2026-10-08');
        $reminder = DB::table('milestone_reminders')->where('reminder_date', '2026-11-08')->first();
        $deviceToken = 'encrypted-device-secret';
        DB::table('device_tokens')->insert([
            'user_id' => $owner->id, 'fcm_token' => Crypt::encryptString($deviceToken),
            'token_hash' => hash('sha256', $deviceToken), 'device_type' => 'ios', 'installation_id' => (string) Str::uuid(),
            'last_used_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('device_tokens')->insert([
            'user_id' => $viewer->id, 'fcm_token' => Crypt::encryptString('viewer-device-secret'),
            'token_hash' => hash('sha256', 'viewer-device-secret'), 'device_type' => 'android', 'installation_id' => (string) Str::uuid(),
            'last_used_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $fcm = \Mockery::mock(FcmClient::class);
        $fcm->shouldReceive('isConfigured')->twice()->andReturnTrue();
        $fcm->shouldReceive('send')->twice()->withArgs(fn ($token, $title, $body, $data) => $token === $deviceToken
            && str_contains($title, 'Bé test') && str_contains($body, 'mốc') && $data['type'] === 'milestone_reminder')
            ->andReturn('projects/test/messages/accepted');
        $this->app->instance(FcmClient::class, $fcm);

        Artisan::call('babi:send-milestone-reminders', ['--date' => '2026-11-01', '--time' => '08:00']);
        Artisan::call('babi:send-milestone-reminders', ['--date' => '2026-11-07', '--time' => '08:00']);

        $deliveries = DB::table('milestone_notification_deliveries')->where('milestone_reminder_id', $reminder->id)->orderBy('days_before')->get();
        $this->assertSame([1, 7], $deliveries->pluck('days_before')->map(fn ($value) => (int) $value)->all());
        $this->assertSame(['accepted', 'accepted'], $deliveries->pluck('status')->all());
        $this->assertSame([$owner->id], $deliveries->pluck('user_id')->unique()->all());
        $this->assertSame(['projects/test/messages/accepted'], $deliveries->pluck('provider_message_id')->unique()->all());
        $this->assertNotNull(DB::table('milestone_reminders')->where('id', $reminder->id)->value('notified_7d_at'));
        $this->assertNotNull(DB::table('milestone_reminders')->where('id', $reminder->id)->value('notified_1d_at'));
    }

    public function test_ambiguous_fcm_result_is_not_automatically_retried(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 1)->startOfDay());
        $this->templates();
        $owner = User::factory()->create();
        $childId = $this->familyWithChild($owner, '2026-10-08');
        app(MilestoneReminderService::class)->syncForChild($childId, '2026-10-08');
        $reminder = DB::table('milestone_reminders')->where('reminder_date', '2026-11-08')->first();
        $deviceToken = 'encrypted-device-secret';
        DB::table('device_tokens')->insert([
            'user_id' => $owner->id, 'fcm_token' => Crypt::encryptString($deviceToken),
            'token_hash' => hash('sha256', $deviceToken), 'device_type' => 'ios', 'installation_id' => (string) Str::uuid(),
            'last_used_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $fcm = \Mockery::mock(FcmClient::class);
        $fcm->shouldReceive('isConfigured')->twice()->andReturnTrue();
        $fcm->shouldReceive('send')->once()->andThrow(new FcmDeliveryUnknownException('timeout'));
        $this->app->instance(FcmClient::class, $fcm);

        Artisan::call('babi:send-milestone-reminders', ['--date' => '2026-11-01', '--time' => '08:00']);
        Artisan::call('babi:send-milestone-reminders', ['--date' => '2026-11-01', '--time' => '08:01']);

        $this->assertDatabaseHas('milestone_notification_deliveries', [
            'milestone_reminder_id' => $reminder->id, 'days_before' => 7, 'status' => 'unknown', 'attempt_count' => 1,
        ]);
    }

    public function test_exhausted_stale_milestone_claim_is_finalized_without_an_extra_send(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 1)->startOfDay());
        $this->templates();
        $owner = User::factory()->create();
        $childId = $this->familyWithChild($owner, '2026-10-08');
        app(MilestoneReminderService::class)->syncForChild($childId, '2026-10-08');
        $reminder = DB::table('milestone_reminders')->where('reminder_date', '2026-11-08')->first();
        $deliveryId = DB::table('milestone_notification_deliveries')->insertGetId([
            'milestone_reminder_id' => $reminder->id,
            'days_before' => 7,
            'user_id' => $owner->id,
            'device_key' => hash('sha256', 'expired-claim-device'),
            'status' => 'processing',
            'attempt_count' => 3,
            'claim_token' => (string) Str::uuid(),
            'claimed_at' => now()->subMinutes(6),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $fcm = \Mockery::mock(FcmClient::class);
        $fcm->shouldReceive('isConfigured')->once()->andReturnTrue();
        $fcm->shouldReceive('send')->never();
        $this->app->instance(FcmClient::class, $fcm);

        Artisan::call('babi:send-milestone-reminders', ['--date' => '2026-11-01', '--time' => '08:00']);

        $this->assertDatabaseHas('milestone_notification_deliveries', [
            'id' => $deliveryId,
            'status' => 'failed',
            'attempt_count' => 3,
            'reason_code' => 'retry_limit_reached',
            'claim_token' => null,
            'claimed_at' => null,
            'retry_after' => null,
        ]);
    }

    public function test_exhausted_stale_milestone_claim_is_finalized_without_a_fourth_attempt(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 1)->startOfDay());
        $this->templates();
        $owner = User::factory()->create();
        $childId = $this->familyWithChild($owner, '2026-10-08');
        app(MilestoneReminderService::class)->syncForChild($childId, '2026-10-08');
        $reminder = DB::table('milestone_reminders')->where('reminder_date', '2026-11-08')->first();
        $deliveryId = DB::table('milestone_notification_deliveries')->insertGetId([
            'milestone_reminder_id' => $reminder->id,
            'days_before' => 7,
            'user_id' => $owner->id,
            'device_key' => hash('sha256', 'expired-claim-device'),
            'status' => 'processing',
            'attempt_count' => 3,
            'claim_token' => (string) Str::uuid(),
            'claimed_at' => now()->subMinutes(6),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $fcm = \Mockery::mock(FcmClient::class);
        $fcm->shouldReceive('isConfigured')->once()->andReturnTrue();
        $fcm->shouldReceive('send')->never();
        $this->app->instance(FcmClient::class, $fcm);

        Artisan::call('babi:send-milestone-reminders', ['--date' => '2026-11-01', '--time' => '08:00']);

        $this->assertDatabaseHas('milestone_notification_deliveries', [
            'id' => $deliveryId, 'status' => 'failed', 'attempt_count' => 3,
            'reason_code' => 'retry_limit_reached', 'claim_token' => null,
            'claimed_at' => null, 'retry_after' => null,
        ]);
    }

    public function test_admin_can_manage_templates_without_rewriting_existing_reminder_history(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 1)->startOfDay());
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $owner = User::factory()->create();
        $childId = $this->familyWithChild($owner, '2026-10-31');
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/milestone-templates')->assertOk();
        $templateId = $this->postJson('/api/admin/milestone-templates', [
            'name' => 'First words', 'age_months' => 12, 'description' => 'Mốc do gia đình theo dõi', 'is_recurring' => false,
        ])->assertCreated()->json('data.id');
        app(MilestoneReminderService::class)->syncForChild($childId, '2026-10-31');

        $this->patchJson('/api/admin/milestone-templates/'.$templateId, ['age_months' => 18])->assertStatus(409);
        $this->patchJson('/api/admin/milestone-templates/'.$templateId, ['description' => 'Nội dung đã cập nhật'])->assertOk()
            ->assertJsonPath('data.description', 'Nội dung đã cập nhật');

        Sanctum::actingAs($owner);
        $this->getJson('/api/admin/milestone-templates')->assertForbidden();
    }

    public function test_admin_template_creation_survives_milestone_dispatch_failure(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $owner = User::factory()->create();
        $this->familyWithChild($owner);
        Sanctum::actingAs($admin);
        Bus::shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('Queue unavailable.'));

        $response = $this->postJson('/api/admin/milestone-templates', [
            'name' => 'Queue resilience milestone', 'age_months' => 18,
        ])->assertCreated();

        $this->assertDatabaseHas('milestone_templates', [
            'id' => $response->json('data.id'), 'name' => 'Queue resilience milestone',
        ]);
    }
}
