<?php

use App\Http\Controllers\PasswordResetController;
use Illuminate\Support\Facades\Route;

Route::group(['prefix' => 'password_reset'], function ($router) {
    $router->post('/confirm', [PasswordResetController::class, 'confirmPasswordReset']);
    $router->post('/admin_initiate', [PasswordResetController::class, 'adminInitiate']);
    $router->post('/initiate', [PasswordResetController::class, 'initiate']);
    $router->post('/update_password', [PasswordResetController::class, 'updatePassword']);
    $router->post('/confirm_expired', [PasswordResetController::class, 'confirmExpiredPasswordReset']);
    $router->post('/update_expired_password', [PasswordResetController::class, 'updateExpiredPassword']);
});
