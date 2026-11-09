<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('avatar_url', 500)->nullable();
            $t->boolean('is_admin')->default(false);
            $t->enum('status', ['active', 'deactivated'])->default('active');
        });
        Schema::create('family_spaces', function (Blueprint $t) {
            $t->id();
            $t->foreignId('owner_user_id')->constrained('users')->restrictOnDelete();
            $t->string('name', 150);
            $t->timestamps();
        });
        Schema::create('family_members', function (Blueprint $t) {
            $t->id();
            $t->foreignId('family_space_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->enum('role', ['owner', 'viewer']);
            $t->timestamp('joined_at');
            $t->unsignedBigInteger('managed_user_key')->nullable()->virtualAs("CASE WHEN role = 'owner' THEN user_id ELSE NULL END");
            $t->unique('managed_user_key');
            $t->unique(['family_space_id', 'user_id']);
            $t->timestamps();
        });
        Schema::create('family_invitations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('family_space_id')->constrained()->cascadeOnDelete();
            $t->foreignId('invited_by')->constrained('users')->restrictOnDelete();
            $t->string('email');
            $t->enum('invitation_type', ['family_viewer', 'co_manager']);
            $t->char('token_hash', 64)->unique();
            $t->enum('status', ['pending', 'accepted', 'declined', 'revoked', 'expired'])->default('pending');
            $t->timestamp('expires_at');
            $t->timestamp('responded_at')->nullable();
            $t->timestamps();
            $t->index(['family_space_id', 'email', 'status']);
        });
        Schema::create('child_profiles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('family_space_id')->constrained()->restrictOnDelete();
            $t->string('name', 100);
            $t->date('date_of_birth');
            $t->enum('gender', ['male', 'female']);
            $t->string('blood_type', 5)->nullable();
            $t->unsignedBigInteger('consent_revision')->default(0);
            $t->unsignedBigInteger('data_revision')->default(0);
            $t->timestamps();
        });
        Schema::create('child_data_consents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('child_profile_id')->constrained()->cascadeOnDelete();
            $t->foreignId('given_by')->constrained('users')->restrictOnDelete();
            $t->string('purpose', 30)->default('storage');
            $t->string('policy_version', 80);
            $t->boolean('representative_confirmed');
            $t->timestamp('granted_at');
            $t->timestamp('withdrawn_at')->nullable();
            $t->timestamps();
        });
        Schema::create('vaccine_templates', function (Blueprint $t) {
            $t->id();
            $t->string('vaccine_code', 60);
            $t->string('name', 150);
            $t->unsignedSmallInteger('dose_number');
            $t->string('disease', 200);
            $t->enum('program', ['expanded', 'service']);
            $t->string('schedule_version', 80);
            $t->enum('timing_rule', ['age', 'clinical']);
            $t->unsignedSmallInteger('age_value')->nullable();
            $t->enum('age_unit', ['day', 'month'])->nullable();
            $t->foreignId('previous_template_id')->nullable()->constrained('vaccine_templates')->restrictOnDelete();
            $t->unsignedSmallInteger('min_interval_days')->nullable();
            $t->text('timing_note')->nullable();
            $t->string('source_url', 1000);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['vaccine_code', 'dose_number', 'program', 'schedule_version'], 'vaccine_dose_version_unique');
        });
        Schema::create('vaccination_schedules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('child_profile_id')->constrained()->cascadeOnDelete();
            $t->foreignId('vaccine_template_id')->constrained()->restrictOnDelete();
            $t->date('due_date')->nullable();
            $t->date('appointment_date')->nullable();
            $t->string('appointment_source')->nullable();
            $t->enum('status', ['unrecorded', 'pending', 'recorded'])->default('unrecorded');
            $t->timestamp('notified_7d_at')->nullable();
            $t->timestamp('notified_1d_at')->nullable();
            $t->timestamps();
            $t->unique(['child_profile_id', 'vaccine_template_id'], 'child_template_unique');
        });
        Schema::create('health_records', function (Blueprint $t) {
            $t->id();
            $t->foreignId('child_profile_id')->constrained()->cascadeOnDelete();
            $t->date('measured_at');
            $t->decimal('height_cm', 5, 1);
            $t->decimal('weight_kg', 5, 2);
            $t->enum('measurement_method', ['lying', 'standing']);
            $t->unsignedInteger('age_days');
            $t->timestamp('outlier_confirmed_at')->nullable();
            $t->text('notes')->nullable();
            $t->unsignedBigInteger('version')->default(0);
            $t->timestamps();
            $t->unique(['child_profile_id', 'measured_at']);
        });
        Schema::create('vaccination_records', function (Blueprint $t) {
            $t->id();
            $t->foreignId('child_profile_id')->constrained()->cascadeOnDelete();
            $t->foreignId('vaccination_schedule_id')->nullable()->constrained()->cascadeOnDelete();
            $t->string('vaccine_name', 150);
            $t->unsignedSmallInteger('dose_number')->nullable();
            $t->date('administered_date')->nullable();
            $t->enum('date_precision', ['exact', 'unknown']);
            $t->string('facility')->nullable();
            $t->enum('source', ['document', 'parent_report']);
            $t->enum('status', ['active', 'voided'])->default('active');
            $t->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $t->text('notes')->nullable();
            $t->unsignedBigInteger('active_schedule_id')->nullable()->virtualAs("CASE WHEN status = 'active' THEN vaccination_schedule_id ELSE NULL END");
            $t->unique('active_schedule_id');
            $t->unsignedBigInteger('version')->default(0);
            $t->timestamps();
        });
        Schema::create('vaccination_record_changes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('vaccination_record_id')->constrained()->cascadeOnDelete();
            $t->foreignId('changed_by')->constrained('users')->restrictOnDelete();
            $t->enum('action', ['create', 'correct', 'void']);
            $t->string('reason', 500);
            $t->json('before_data')->nullable();
            $t->json('after_data');
            $t->timestamps();
        });
        Schema::create('operation_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->uuid('operation_key');
            $t->string('operation_type', 50);
            $t->char('payload_hash', 64);
            $t->enum('status', ['processing', 'completed', 'failed']);
            $t->string('resource_type', 50)->nullable();
            $t->unsignedBigInteger('resource_id')->nullable();
            $t->json('cleanup_manifest')->nullable();
            $t->timestamp('expires_at');
            $t->timestamps();
            $t->unique(['actor_id', 'operation_key']);
        });
    }

    public function down(): void
    {
        foreach (['operation_requests', 'vaccination_record_changes', 'vaccination_records', 'health_records', 'vaccination_schedules', 'vaccine_templates', 'child_data_consents', 'child_profiles', 'family_invitations', 'family_members', 'family_spaces'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['avatar_url', 'is_admin', 'status']));
    }
};
