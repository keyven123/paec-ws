<?php

use App\Http\Controllers\ActivityComplianceController;
use Illuminate\Support\Facades\Route;

Route::prefix('activity-compliances')->group(function () {
    Route::middleware(['can:activity-compliances-view'])->group(function () {
        Route::get('/organizations/{organizationUuid}', [ActivityComplianceController::class, 'indexByOrganization']);
    });

    Route::middleware(['can:activity-compliances-create'])->group(function () {
        Route::post('/', [ActivityComplianceController::class, 'store']);
    });

    Route::middleware(['can:activity-compliances-update'])->group(function () {
        Route::patch('/{uuid}', [ActivityComplianceController::class, 'update']);
    });

    Route::middleware(['can:activity-compliances-delete'])->group(function () {
        Route::delete('/{uuid}', [ActivityComplianceController::class, 'destroy']);
    });
});
