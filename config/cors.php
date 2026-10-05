<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS)
    |--------------------------------------------------------------------------
    |
    | Supports:
    | - Local: Vite on :3000 → php artisan serve
    | - Split: Vercel frontend → Hostinger API
    | - Same-origin: SPA + API on Hostinger (APP_URL)
    |
    | On Hostinger set at least:
    |   FRONTEND_URL=https://your-paec-app.vercel.app
    |   CORS_ALLOWED_ORIGINS=https://your-paec-app.vercel.app
    | then: php artisan config:clear
    |
    */

    'paths' => ['api', 'api/*', 'sanctum/csrf-cookie', 'broadcasting/auth'],

    'allowed_methods' => ['*'],

    'allowed_origins' => (static function (): array {
        $normalize = static function (?string $origin): ?string {
            $origin = rtrim(trim((string) $origin), '/');

            return $origin !== '' ? $origin : null;
        };

        $origins = [
            $normalize(env('APP_URL', 'http://127.0.0.1:8000')),
            $normalize(env('FRONTEND_URL')),
            // Local Vite (always allowed so local UI can talk to local or remote API)
            'http://localhost:3000',
            'http://127.0.0.1:3000',
            'http://localhost:5173',
            'http://127.0.0.1:5173',
            'http://localhost:8000',
            'http://127.0.0.1:8000',
        ];

        foreach (explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')) as $extra) {
            $origins[] = $normalize($extra);
        }

        return array_values(array_unique(array_filter($origins)));
    })(),

    // Vercel production + preview / branch deploys: https://*.vercel.app
    'allowed_origins_patterns' => [
        '#^https://[a-z0-9-]+\.vercel\.app$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 600,

    // JWT Bearer auth does not need cookies; false avoids credential CORS edge cases.
    'supports_credentials' => false,

];
