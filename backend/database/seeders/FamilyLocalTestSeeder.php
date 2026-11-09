<?php

namespace Database\Seeders;

use App\Models\ChildProfile;
use App\Models\User;
use App\Services\VaccinationScheduleService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Creates disposable, synthetic data for exercising the family-sharing UI.
 * Never run this seeder outside an explicitly enabled local environment.
 */
class FamilyLocalTestSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local') || ! filter_var(env('BABI_LOCAL_TEST_FIXTURES'), FILTER_VALIDATE_BOOL)) {
            throw new \RuntimeException('Local family fixtures require APP_ENV=local and BABI_LOCAL_TEST_FIXTURES=true.');
        }

        $version = config('babi.vaccine_catalog_version');
        if (! $version || ! DB::table('vaccine_templates')->where('program', 'expanded')->where('schedule_version', $version)->where('is_active', true)->exists()) {
            throw new \RuntimeException('Import the reviewed expanded-program catalog before creating the local family fixture.');
        }

        $password = 'BabiLocalTest2026!';

        DB::transaction(function () use ($password) {
            $owner = $this->testUser('babi.owner@example.test', 'Babi Local Owner', $password);
            $guest = $this->testUser('babi.viewer@example.test', 'Babi Local Viewer', $password);

            $spaceId = DB::table('family_spaces')->where('owner_user_id', $owner->id)->where('name', 'Babi · LOCAL TEST')->value('id');
            if (! $spaceId) {
                $spaceId = DB::table('family_spaces')->insertGetId([
                    'owner_user_id' => $owner->id,
                    'name' => 'Babi · LOCAL TEST',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('family_members')->updateOrInsert(
                ['family_space_id' => $spaceId, 'user_id' => $owner->id],
                ['role' => 'owner', 'joined_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            );

            $birthDate = '2025-10-20';
            $child = ChildProfile::firstOrCreate(
                ['family_space_id' => $spaceId, 'name' => 'Bé Kiểm Thử'],
                [
                    'user_id' => $owner->id,
                    'date_of_birth' => $birthDate,
                    'gender' => 'female',
                    'consent_revision' => 1,
                    'data_revision' => 1,
                ],
            );

            if (! DB::table('child_data_consents')->where('child_profile_id', $child->id)->where('purpose', 'storage')->whereNull('withdrawn_at')->exists()) {
                DB::table('child_data_consents')->insert([
                    'child_profile_id' => $child->id,
                    'given_by' => $owner->id,
                    'purpose' => 'storage',
                    'policy_version' => config('babi.policy_version'),
                    'representative_confirmed' => true,
                    'granted_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            if (! DB::table('vaccination_schedules')->where('child_profile_id', $child->id)->exists()) {
                app(VaccinationScheduleService::class)->create($child->id, $birthDate);
            }

            // The second account is verified only inside this disposable local database,
            // so both invitation acceptance and link-approval flows can be exercised.
            if ($guest->email_verified_at === null) {
                $guest->forceFill(['email_verified_at' => now()])->save();
            }
        });

        $this->command?->info('Local family test data is ready.');
        $this->command?->line('Owner: babi.owner@example.test');
        $this->command?->line('Invitee: babi.viewer@example.test');
        $this->command?->line('Password for both: '.$password);
    }

    private function testUser(string $email, string $name, string $password): User
    {
        $user = User::firstOrNew(['email' => $email]);
        $user->forceFill([
            'name' => $name,
            'password' => $password,
            'email_verified_at' => now(),
            'status' => 'active',
        ])->save();

        return $user;
    }
}
