<?php

use App\Http\Controllers\PermissionController;
use App\Http\Controllers\RoleController;
use Illuminate\Support\Facades\Route;

// Role management routes (admin only)
Route::middleware(['role:admin,superadmin'])->group(function () {
    Route::get('roles', [RoleController::class, 'index'])->middleware('can:roles-view');
    Route::get('roles/{uuid}', [RoleController::class, 'show'])->middleware('can:roles-view');
    Route::post('roles', [RoleController::class, 'store'])->middleware('can:roles-create');
    Route::put('roles/{uuid}', [RoleController::class, 'update'])->middleware('can:roles-update');
    Route::delete('roles/{uuid}', [RoleController::class, 'destroy'])->middleware('can:roles-delete');
    Route::post('roles/{uuid}/permissions', [RoleController::class, 'assignPermissions'])->middleware('can:roles-update');

    Route::get('permissions/merchant-partner/catalog', [PermissionController::class, 'merchantPartnerCatalog'])->middleware();
    Route::get('permissions/merchant-partner', [PermissionController::class, 'merchantPartnerPermissions'])->middleware();
    Route::get('permissions', [PermissionController::class, 'index'])->middleware();
    Route::get('permissions/access/{access}', [PermissionController::class, 'getByAccess']);
    Route::get('permissions/role-scope/{roleScope}', [PermissionController::class, 'getByRoleScope']);
    Route::get('permissions/{uuid}', [PermissionController::class, 'show']);
});
