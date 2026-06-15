<?php

namespace Tests\Support;

use Illuminate\Routing\Route;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;

class ApiRouteInspector
{
    private const SAMPLE_UUID = '00000000-0000-4000-8000-000000000001';

    private const PLACEHOLDERS = [
        'uuid' => self::SAMPLE_UUID,
        'location_uuid' => '00000000-0000-4000-8000-000000000002',
        'blocked_date_uuid' => '00000000-0000-4000-8000-000000000003',
        'threadUuid' => '00000000-0000-4000-8000-000000000004',
        'transactionUuid' => '00000000-0000-4000-8000-000000000005',
        'inquiryUuid' => '00000000-0000-4000-8000-000000000006',
        'slug' => 'about-us',
        'code' => 'TESTCODE',
        'access' => 'read',
        'roleScope' => 'admin',
    ];

    /**
     * Routes excluded from automated smoke tests.
     *
     * @var array<int, string>
     */
    private const SKIP_URI_PATTERNS = [
        'api/v1/test-invalid',
        'api/v1/auth/google/redirect',
        'api/v1/auth/google/callback',
        'api/v1/auth/facebook/redirect',
        'api/v1/auth/facebook/callback',
        'api/v1/check-permissions',
    ];

    /**
     * @return Collection<int, array{method: string, uri: string, auth: string, middleware: array<int, string>}>
     */
    public static function routes(?string $httpMethod = null, array $placeholders = []): Collection
    {
        $resolvedPlaceholders = array_merge(self::PLACEHOLDERS, $placeholders);

        return collect(RouteFacade::getRoutes())
            ->map(fn (Route $route) => self::normalizeRoute($route, $resolvedPlaceholders))
            ->filter(fn (array $route) => Str::startsWith($route['uri'], 'api'))
            ->filter(fn (array $route) => !self::shouldSkip($route))
            ->when($httpMethod !== null, fn (Collection $routes) => $routes->filter(
                fn (array $route) => $route['method'] === strtoupper($httpMethod),
            ))
            ->values();
    }

    /**
     * @return array{method: string, uri: string, auth: string, middleware: array<int, string>}
     */
    private static function normalizeRoute(Route $route, array $placeholders): array
    {
        $methods = collect($route->methods())
            ->reject(fn (string $method) => $method === 'HEAD')
            ->values();

        $method = (string) ($methods->first() ?? 'GET');
        $middleware = collect($route->middleware())->values()->all();

        return [
            'method' => $method,
            'uri' => self::resolveUri($route->uri(), $placeholders),
            'auth' => self::resolveAuthLevel($middleware),
            'middleware' => $middleware,
        ];
    }

    private static function resolveUri(string $uri, array $placeholders): string
    {
        $resolved = $uri;

        foreach ($placeholders as $parameter => $value) {
            $resolved = str_replace('{' . $parameter . '}', (string) $value, $resolved);
        }

        return $resolved;
    }

    /**
     * @param array<int, string> $middleware
     */
    private static function resolveAuthLevel(array $middleware): string
    {
        if (in_array('auth:admin', $middleware, true)) {
            return 'admin';
        }

        if (in_array('auth:api', $middleware, true)) {
            return 'customer';
        }

        return 'public';
    }

    /**
     * @param array{method: string, uri: string, auth: string, middleware: array<int, string>} $route
     */
    private static function shouldSkip(array $route): bool
    {
        $uri = $route['uri'];

        foreach (self::SKIP_URI_PATTERNS as $pattern) {
            if ($uri === $pattern || Str::startsWith($uri, $pattern)) {
                return true;
            }
        }

        foreach ($route['middleware'] as $middleware) {
            if (Str::startsWith($middleware, 'portal:')) {
                return true;
            }
        }

        if (Str::contains($uri, 'customer/venue-inquiries/') && !Str::contains($uri, 'my-venue-inquiries')) {
            return true;
        }

        if (Str::contains($uri, 'public/events/') && (
            Str::endsWith($uri, '/seats') ||
            Str::endsWith($uri, '/seats-v2')
        )) {
            return true;
        }

        // Finance module is under active adjustment — exclude from smoke tests for now.
        if (Str::contains($uri, 'admin/finance/')) {
            return true;
        }

        if (Str::startsWith($uri, 'api/v1/organizer/')) {
            return true;
        }

        if (Str::startsWith($uri, 'api/v1/organizations/')) {
            return true;
        }

        if (Str::contains($uri, 'venue-listings/inquiries/') && Str::contains($uri, '/chat')) {
            return true;
        }

        if (config('database.default') === 'sqlite' && (
            Str::contains($uri, 'platform-pnl') ||
            $uri === 'api/v1/analytics/sales'
        )) {
            return true;
        }

        return false;
    }

    public static function isAcceptableSmokeStatus(int $status, string $method, string $auth, bool $authenticated): bool
    {
        if ($status >= 500) {
            return false;
        }

        if (!$authenticated && in_array($auth, ['admin', 'customer'], true)) {
            return $status === 401;
        }

        if ($method === 'GET' && $status === 401 && $authenticated) {
            return false;
        }

        return in_array($status, [200, 201, 202, 204, 302, 400, 401, 403, 404, 405, 422], true);
    }

    public static function formatRouteLabel(array $route): string
    {
        return sprintf('%s %s', $route['method'], $route['uri']);
    }
}
