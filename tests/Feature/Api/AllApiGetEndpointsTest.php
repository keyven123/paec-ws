<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ApiRouteInspector;
use Tests\Support\Concerns\AuthenticatesApiUsers;
use Tests\TestCase;

class AllApiGetEndpointsTest extends TestCase
{
    use AuthenticatesApiUsers;
    use RefreshDatabase;

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

    public function test_all_public_get_api_endpoints_respond_without_server_error(): void
    {
        $this->assertGetRoutesRespond('public', false);
    }

    public function test_all_customer_get_api_endpoints_require_authentication(): void
    {
        $this->assertGetRoutesRespond('customer', false, expectedUnauthenticatedStatus: 401);
    }

    public function test_all_customer_get_api_endpoints_respond_when_authenticated(): void
    {
        $this->authenticateCustomer();
        $this->assertGetRoutesRespond('customer', true);
    }

    public function test_all_admin_get_api_endpoints_require_authentication(): void
    {
        $this->assertGetRoutesRespond('admin', false, expectedUnauthenticatedStatus: 401);
    }

    public function test_all_admin_get_api_endpoints_respond_when_authenticated(): void
    {
        $this->authenticateAdmin();
        $this->assertGetRoutesRespond('admin', true);
    }

    private function assertGetRoutesRespond(
        string $authLevel,
        bool $authenticated,
        ?int $expectedUnauthenticatedStatus = null,
    ): void {
        $routes = ApiRouteInspector::routes('GET', $this->routePlaceholders)
            ->filter(fn (array $route) => $route['auth'] === $authLevel);

        $this->assertNotEmpty($routes, "No GET routes found for auth level [{$authLevel}]");

        foreach ($routes as $route) {
            if ($authenticated) {
                $token = $authLevel === 'admin'
                    ? $this->authenticateAdmin()
                    : $this->authenticateCustomer();

                $response = $this->withToken($token)->json('GET', '/' . $route['uri']);
            } else {
                $response = $this->json('GET', '/' . $route['uri']);
            }

            $status = $response->getStatusCode();
            $label = ApiRouteInspector::formatRouteLabel($route);

            if (!$authenticated && $expectedUnauthenticatedStatus !== null) {
                $this->assertSame(
                    $expectedUnauthenticatedStatus,
                    $status,
                    "{$label} should return {$expectedUnauthenticatedStatus} when unauthenticated, got {$status}",
                );

                continue;
            }

            if ($route['uri'] === 'api/v1/test-invalid') {
                $this->assertContains($status, [403, 401], "{$label} unexpected status {$status}");

                continue;
            }

            $this->assertTrue(
                ApiRouteInspector::isAcceptableSmokeStatus($status, 'GET', $authLevel, $authenticated),
                "{$label} returned unacceptable status {$status}",
            );
        }
    }
}
