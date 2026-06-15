<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ApiRouteInspector;
use Tests\Support\Concerns\AuthenticatesApiUsers;
use Tests\TestCase;

class AllApiMutationEndpointsTest extends TestCase
{
    use AuthenticatesApiUsers;
    use RefreshDatabase;

    private const MUTATION_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    private array $routePlaceholders = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedApiDatabase();

        $event = $this->createPublishedEventForTests();
        $this->routePlaceholders = [
            'uuid' => $event->uuid,
            'slug' => $event->slug,
        ];
    }

    public function test_protected_mutation_endpoints_require_authentication(): void
    {
        $routes = ApiRouteInspector::routes(null, $this->routePlaceholders)
            ->filter(fn (array $route) => in_array($route['method'], self::MUTATION_METHODS, true))
            ->filter(fn (array $route) => in_array($route['auth'], ['admin', 'customer'], true));

        $this->assertNotEmpty($routes);

        foreach ($routes as $route) {
            $response = $this->json($route['method'], '/' . $route['uri']);
            $label = ApiRouteInspector::formatRouteLabel($route);

            $this->assertSame(
                401,
                $response->getStatusCode(),
                "{$label} should return 401 when unauthenticated",
            );
        }
    }

    public function test_admin_mutation_endpoints_do_not_server_error_when_authenticated(): void
    {
        $token = $this->authenticateAdmin();

        $routes = ApiRouteInspector::routes(null, $this->routePlaceholders)
            ->filter(fn (array $route) => in_array($route['method'], self::MUTATION_METHODS, true))
            ->filter(fn (array $route) => $route['auth'] === 'admin');

        $this->assertNotEmpty($routes);

        foreach ($routes as $route) {
            $response = $this->withToken($token)->json($route['method'], '/' . $route['uri']);
            $status = $response->getStatusCode();
            $label = ApiRouteInspector::formatRouteLabel($route);

            $this->assertTrue(
                ApiRouteInspector::isAcceptableSmokeStatus($status, $route['method'], 'admin', true),
                "{$label} returned unacceptable status {$status}",
            );
        }
    }

    public function test_customer_mutation_endpoints_do_not_server_error_when_authenticated(): void
    {
        $token = $this->authenticateCustomer();

        $routes = ApiRouteInspector::routes(null, $this->routePlaceholders)
            ->filter(fn (array $route) => in_array($route['method'], self::MUTATION_METHODS, true))
            ->filter(fn (array $route) => $route['auth'] === 'customer');

        $this->assertNotEmpty($routes);

        foreach ($routes as $route) {
            $response = $this->withToken($token)->json($route['method'], '/' . $route['uri']);
            $status = $response->getStatusCode();
            $label = ApiRouteInspector::formatRouteLabel($route);

            $this->assertTrue(
                ApiRouteInspector::isAcceptableSmokeStatus($status, $route['method'], 'customer', true),
                "{$label} returned unacceptable status {$status}",
            );
        }
    }

    public function test_public_mutation_endpoints_do_not_server_error(): void
    {
        $routes = ApiRouteInspector::routes(null, $this->routePlaceholders)
            ->filter(fn (array $route) => in_array($route['method'], self::MUTATION_METHODS, true))
            ->filter(fn (array $route) => $route['auth'] === 'public');

        $this->assertNotEmpty($routes);

        foreach ($routes as $route) {
            $response = $this->json($route['method'], '/' . $route['uri']);
            $status = $response->getStatusCode();
            $label = ApiRouteInspector::formatRouteLabel($route);

            $this->assertTrue(
                ApiRouteInspector::isAcceptableSmokeStatus($status, $route['method'], 'public', false),
                "{$label} returned unacceptable status {$status}",
            );
        }
    }
}
