<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'type' => 'api',
        'name' => 'PAEC',
        'version' => '1.0.0',
        'description' => 'PAEC API',
        'api' => url('/api'),
        'health' => url('/api/health'),
    ]);
});
