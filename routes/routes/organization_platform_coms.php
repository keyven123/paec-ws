<?php

use App\Http\Controllers\OrganizationPlatformComController;
use Illuminate\Support\Facades\Route;

Route::prefix('organization-platform-coms')->group(function () {
    Route::middleware(['can:commissions-view'])->group(function () {
        Route::get('/', [OrganizationPlatformComController::class, 'index']);
        Route::get('/{uuid}', [OrganizationPlatformComController::class, 'show']);
    });
});
