<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use App\Providers\EventServiceProvider;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(HandleCors::class);

        // Register custom middleware
        $middleware->alias([
            'role' => \App\Http\Middleware\CheckRole::class,
            'permission' => \App\Http\Middleware\CheckPermission::class,
            'can' => \App\Http\Middleware\CheckAbility::class,
            'auth.admin' => \App\Http\Middleware\AuthenticateAdmin::class,
        ]);
    })
    ->withProviders([
        EventServiceProvider::class,
        App\Providers\QrCodeServiceProviderAlias::class,
        SimpleSoftwareIO\QrCode\QrCodeServiceProvider::class,
    ])
    ->withExceptions(function (Exceptions $exceptions): void {
        // Handle unauthenticated API requests
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'Unauthenticated.',
                    'error' => 'Authentication required'
                ], 401);
            }
            return null;
        });
    })->create();
