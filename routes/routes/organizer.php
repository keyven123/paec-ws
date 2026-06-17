<?php

use App\Http\Controllers\Customer\ProfileController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\Organizer\OrganizerAccountingController;
use App\Http\Controllers\Organizer\OrganizerAdminUserController;
use App\Http\Controllers\Organizer\OrganizerDashboardController;
use App\Http\Controllers\Organizer\OrganizerOrganizationBankController;
use App\Http\Controllers\Organizer\OrganizerPermissionController;
use App\Http\Controllers\Organizer\OrganizerRoleController;
use Illuminate\Support\Facades\Route;

Route::group(['prefix' => 'organizer'], function () {
    Route::post('/onboarding', [OrganizationController::class, 'onboarding']);
    Route::post('/onboarding/register', [OrganizationController::class, 'onboardingRegister']);

    Route::middleware(['auth:admin'])->group(function () {
        Route::prefix('dashboard')->group(function () {
            Route::get('/stats', [OrganizerDashboardController::class, 'dashboardStats'])->middleware('can:organizers-view');
            Route::get('/recent-activities', [OrganizerDashboardController::class, 'recentActivities'])->middleware('can:organizers-view');
            Route::get('/upcoming-events', [OrganizerDashboardController::class, 'upcomingEvents'])->middleware('can:organizers-view');
        });
        Route::prefix('accounting')->group(function () {
            Route::get('/pnl', [OrganizerAccountingController::class, 'pnl'])->middleware('can:organizer-accounting-view');
            Route::get('/events', [OrganizerAccountingController::class, 'events'])->middleware('can:organizer-accounting-view');
            Route::get('/summary', [OrganizerAccountingController::class, 'summary'])->middleware('can:organizer-accounting-view');
            Route::get('/remittance-buckets', [OrganizerAccountingController::class, 'remittanceBuckets'])->middleware('can:organizer-accounting-view');
            Route::get('/transactions', [OrganizerAccountingController::class, 'transactions'])->middleware('can:organizer-accounting-view');
            Route::get('/payout-requests', [OrganizerAccountingController::class, 'payoutRequests'])->middleware('can:organizer-accounting-view');
            Route::post('/payout-requests', [OrganizerAccountingController::class, 'storePayoutRequest'])->middleware('can:organizer-accounting-create');
        });
        Route::prefix('profile')->group(function () {
            Route::get('/', [ProfileController::class, 'getOrganizerProfile'])->middleware('can:profile-view');
            Route::put('/', [ProfileController::class, 'updateOrganizerProfile'])->middleware('can:profile-update');
            Route::get('/organization', [ProfileController::class, 'getOrganizationProfile'])->middleware('can:profile-view');
            Route::put('/organization', [ProfileController::class, 'updateOrganizationProfile'])->middleware('can:profile-update');
            Route::prefix('organization/banks')->group(function () {
                Route::get('/', [OrganizerOrganizationBankController::class, 'index'])->middleware('can:profile-view');
                Route::put('/sync', [OrganizerOrganizationBankController::class, 'sync'])->middleware('can:profile-update');
                Route::post('/', [OrganizerOrganizationBankController::class, 'store'])->middleware('can:profile-update');
                Route::put('/{uuid}', [OrganizerOrganizationBankController::class, 'update'])->middleware('can:profile-update');
                Route::delete('/{uuid}', [OrganizerOrganizationBankController::class, 'destroy'])->middleware('can:profile-update');
            });
        });

        Route::prefix('admin-users')->group(function () {
            Route::get('/', [OrganizerAdminUserController::class, 'index'])->middleware('can:admin-users-view');
            Route::post('/', [OrganizerAdminUserController::class, 'store'])->middleware('can:admin-users-create');
            Route::get('/available-roles', [OrganizerAdminUserController::class, 'availableRoles'])->middleware('can:admin-users-view');
            Route::get('/{uuid}', [OrganizerAdminUserController::class, 'show'])->middleware('can:admin-users-view');
            Route::put('/{uuid}', [OrganizerAdminUserController::class, 'update'])->middleware('can:admin-users-update');
            Route::delete('/{uuid}', [OrganizerAdminUserController::class, 'destroy'])->middleware('can:admin-users-delete');
        });

        Route::prefix('roles')->group(function () {
            Route::get('/', [OrganizerRoleController::class, 'index'])->middleware('can:roles-view');
            Route::post('/', [OrganizerRoleController::class, 'store'])->middleware('can:roles-create');
            Route::get('/{uuid}', [OrganizerRoleController::class, 'show'])->middleware('can:roles-view');
            Route::put('/{uuid}', [OrganizerRoleController::class, 'update'])->middleware('can:roles-update');
            Route::delete('/{uuid}', [OrganizerRoleController::class, 'destroy'])->middleware('can:roles-delete');
            Route::post('/{uuid}/permissions', [OrganizerRoleController::class, 'assignPermissions'])->middleware('can:roles-update');
        });

        Route::get('permissions/catalog', [OrganizerPermissionController::class, 'catalog'])->middleware('can:roles-view');
        Route::get('permissions', [OrganizerPermissionController::class, 'index'])->middleware('can:roles-view');
    });
});
