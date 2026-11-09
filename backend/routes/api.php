<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AppointmentReminderController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChildProfileController;
use App\Http\Controllers\ChildProfileExportController;
use App\Http\Controllers\DeviceTokenController;
use App\Http\Controllers\FamilyController;
use App\Http\Controllers\HealthRecordController;
use App\Http\Controllers\MemoryController;
use App\Http\Controllers\MilestoneReminderController;
use App\Http\Controllers\NotificationPreferenceController;
use App\Http\Controllers\VaccinationController;
use App\Services\VaccinationScheduleService;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:api')->group(function () {
    Route::post('auth/register', [AuthController::class, 'register'])->middleware('throttle:register');
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('auth/password/forgot', [AuthController::class, 'sendPasswordReset'])->middleware('throttle:email');
    Route::post('auth/password/reset', [AuthController::class, 'resetPassword'])->middleware('throttle:email');
    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::get('app-config', fn (VaccinationScheduleService $catalog) => response()->json(['success' => true, 'data' => [
            'policy_version' => config('babi.policy_version'),
            'vaccine_catalog_ready' => $catalog->currentTemplates()->isNotEmpty(),
            'vaccine_catalog_version' => $catalog->currentVersion(),
        ]]));
        Route::patch('admin/users/{id}/status', [AdminController::class, 'updateUserStatus']);
        Route::get('admin/vaccine-catalogs', [AdminController::class, 'vaccineCatalogs']);
        Route::post('admin/vaccine-catalogs', [AdminController::class, 'importVaccineCatalog']);
        Route::put('admin/vaccine-catalogs/active', [AdminController::class, 'activateVaccineCatalog']);
        Route::get('admin/milestone-templates', [AdminController::class, 'milestoneTemplates']);
        Route::post('admin/milestone-templates', [AdminController::class, 'createMilestoneTemplate']);
        Route::patch('admin/milestone-templates/{id}', [AdminController::class, 'updateMilestoneTemplate']);
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::put('auth/me', [AuthController::class, 'update']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::post('auth/password', [AuthController::class, 'password']);
        Route::post('auth/email/verification', [AuthController::class, 'sendVerification'])->middleware('throttle:email');
        Route::post('auth/email/verify', [AuthController::class, 'verify'])->name('verification.verify');
        Route::get('notification-preferences', [NotificationPreferenceController::class, 'show']);
        Route::put('notification-preferences', [NotificationPreferenceController::class, 'update']);
        Route::put('device-tokens', [DeviceTokenController::class, 'store']);
        Route::delete('device-tokens', [DeviceTokenController::class, 'destroy']);

        Route::get('child-profiles', [ChildProfileController::class, 'index']);
        Route::post('child-profiles', [ChildProfileController::class, 'store']);
        Route::get('child-profiles/{id}', [ChildProfileController::class, 'show']);
        Route::delete('child-profiles/{id}', [ChildProfileController::class, 'destroy']);
        Route::post('child-profiles/{id}/exports', [ChildProfileExportController::class, 'store']);
        Route::get('child-profile-exports/{id}', [ChildProfileExportController::class, 'show']);
        Route::get('child-profile-exports/{id}/download', [ChildProfileExportController::class, 'download']);
        Route::get('child-profiles/{id}/avatar', [ChildProfileController::class, 'avatar']);
        Route::put('child-profiles/{id}', [ChildProfileController::class, 'update']);
        Route::get('child-profiles/{id}/consents', [ChildProfileController::class, 'consents']);
        Route::post('child-profiles/{id}/consents', [ChildProfileController::class, 'grant']);
        Route::post('child-data-consents/{id}/withdraw', [ChildProfileController::class, 'withdraw']);
        Route::get('operations/{key}', [ChildProfileController::class, 'operation']);
        Route::get('milestones', [MemoryController::class, 'milestones']);
        Route::get('child-profiles/{id}/memories', [MemoryController::class, 'index']);
        Route::get('child-profiles/{id}/milestone-reminders', [MilestoneReminderController::class, 'index']);
        Route::post('milestone-reminders/{id}/complete', [MilestoneReminderController::class, 'complete']);
        Route::post('memories', [MemoryController::class, 'store']);
        Route::get('memories/{id}', [MemoryController::class, 'show']);
        Route::put('memories/{id}', [MemoryController::class, 'update']);
        Route::delete('memories/{id}', [MemoryController::class, 'destroy']);
        Route::post('memories/{id}/retry', [MemoryController::class, 'retry']);
        Route::post('memories/{id}/reactions', [MemoryController::class, 'reaction']);
        Route::get('memories/{id}/comments', [MemoryController::class, 'comments']);
        Route::post('memories/{id}/comments', [MemoryController::class, 'comment']);
        Route::delete('comments/{id}', [MemoryController::class, 'deleteComment']);
        Route::get('media-files/{id}', [MemoryController::class, 'media']);

        Route::get('family-spaces', [FamilyController::class, 'index']);
        Route::get('family-spaces/{id}/members', [FamilyController::class, 'members']);
        Route::get('family-spaces/{id}/vaccination-reminder-settings', [FamilyController::class, 'vaccinationReminderSettings']);
        Route::put('family-spaces/{id}/vaccination-reminder-settings', [FamilyController::class, 'updateVaccinationReminderSettings']);
        Route::get('family-spaces/{id}/vaccination-reminder-deliveries', [FamilyController::class, 'vaccinationReminderDeliveries']);
        Route::get('family-spaces/{id}/invitations', [FamilyController::class, 'invitations']);
        // Retired email-proposal flow: stale clients must not send invitations.
        Route::match(['get', 'post'], 'family-spaces/{id}/invitation-proposals', fn () => response()->json(['success' => false, 'message' => 'Hãy chia sẻ link gia đình. Người nhận gửi yêu cầu để Owner duyệt.'], 410));
        Route::post('family-invitation-proposals/{id}/{action}', fn () => response()->json(['success' => false, 'message' => 'Luồng đề nghị mời email đã ngừng. Hãy dùng link gia đình.'], 410));
        Route::post('family-spaces/{id}/invitations', [FamilyController::class, 'invite'])->middleware('throttle:email');
        Route::get('family-spaces/{id}/join-links', [FamilyController::class, 'joinLinks']);
        Route::post('family-spaces/{id}/join-links', [FamilyController::class, 'createJoinLink'])->middleware('throttle:email');
        Route::delete('family-join-links/{id}', [FamilyController::class, 'revokeJoinLink']);
        Route::get('family-spaces/{id}/join-requests', [FamilyController::class, 'joinRequests']);
        Route::post('family-space-join-requests/preview', [FamilyController::class, 'previewJoin']);
        Route::post('family-space-join-requests', [FamilyController::class, 'requestJoin']);
        Route::get('family-space-join-requests/{id}', [FamilyController::class, 'joinRequestStatus']);
        Route::post('family-join-requests/{id}/approve', [FamilyController::class, 'approveJoinRequest']);
        Route::post('family-join-requests/{id}/decline', [FamilyController::class, 'declineJoinRequest']);
        Route::post('family-invitations/accept', [FamilyController::class, 'accept']);
        Route::post('family-invitations/decline', [FamilyController::class, 'decline']);
        Route::post('family-invitations/preview', [FamilyController::class, 'previewInvitation']);
        Route::post('family-invitations/{id}/revoke', [FamilyController::class, 'revoke']);
        Route::post('family-invitations/{id}/resend', [FamilyController::class, 'resend'])->middleware('throttle:email');
        Route::patch('family-members/{id}', [FamilyController::class, 'demote']);
        Route::delete('family-members/{id}', [FamilyController::class, 'remove']);

        Route::get('child-profiles/{id}/health-records', [HealthRecordController::class, 'index']);
        Route::post('health-records', [HealthRecordController::class, 'store']);
        Route::patch('health-records/{id}', [HealthRecordController::class, 'update']);
        Route::delete('health-records/{id}', [HealthRecordController::class, 'destroy']);

        Route::get('child-profiles/{id}/vaccinations', [VaccinationController::class, 'index']);
        Route::put('vaccinations/{id}/appointment', [VaccinationController::class, 'appointment']);
        Route::patch('vaccinations/{id}/mark-pending', [VaccinationController::class, 'pending']);
        Route::get('child-profiles/{id}/vaccination-records', [VaccinationController::class, 'records']);
        Route::get('child-profiles/{id}/appointment-reminders', [AppointmentReminderController::class, 'index']);
        Route::post('child-profiles/{id}/appointment-reminders', [AppointmentReminderController::class, 'store']);
        Route::patch('appointment-reminders/{id}', [AppointmentReminderController::class, 'update']);
        Route::delete('appointment-reminders/{id}', [AppointmentReminderController::class, 'destroy']);
        Route::post('child-profiles/{id}/vaccination-records', [VaccinationController::class, 'storeRecord']);
        Route::patch('vaccination-records/{id}', [VaccinationController::class, 'correct']);
        Route::post('vaccination-records/{id}/void', [VaccinationController::class, 'voidRecord']);
        Route::get('vaccination-records/{id}/changes', [VaccinationController::class, 'changes']);
    });
});
