<?php

use App\Http\Controllers\AdminUserController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin-users')->group(function () {
    Route::get('/', [AdminUserController::class, 'index'])
        ->middleware(['can:admin-users-view']);
    Route::post('/', [AdminUserController::class, 'store'])
        ->middleware(['can:admin-users-create']);
    Route::get('/available-roles', [AdminUserController::class, 'availableRoles'])
        ->middleware(['can:admin-users-view']);
    Route::get('/{uuid}', [AdminUserController::class, 'show'])
        ->middleware(['can:admin-users-view']);
    Route::put('/{uuid}', [AdminUserController::class, 'update'])
        ->middleware(['can:admin-users-update']);
    Route::delete('/{uuid}', [AdminUserController::class, 'destroy'])
        ->middleware(['can:admin-users-delete']);
});
