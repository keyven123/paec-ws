<?php

namespace Tests\Feature\Api;

use App\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ApiRouteInspector;
use Tests\Support\Concerns\AuthenticatesApiUsers;
use Tests\TestCase;

class AdminApiTest extends TestCase
{
    use AuthenticatesApiUsers;
    use RefreshDatabase;

    private Event $event;

    private array $routePlaceholders = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedApiDatabase();

        $this->event = $this->createPublishedEventForTests();
        $this->routePlaceholders = [
            'uuid' => $this->event->uuid,
            'slug' => $this->event->slug,
        ];
    }

    public function test_admin_login_returns_access_token(): void
    {
        $this->postJson('/api/v1/admin/login', [
            'email' => 'admin@paec.com',
            'password' => 'P@ec2026!!',
        ])
            ->assertOk()
            ->assertJsonStructure(['access_token', 'admin_user']);
    }

    public function test_admin_login_rejects_invalid_credentials(): void
    {
        $this->postJson('/api/v1/admin/login', [
            'email' => 'admin@paec.com',
            'password' => 'wrong-password',
        ])->assertStatus(422);
    }

    public function test_admin_me_returns_authenticated_profile(): void
    {
        $this->withToken($this->authenticateAdmin())
            ->getJson('/api/v1/admin/me')
            ->assertOk()
            ->assertJsonPath('data.admin_user.email', 'admin@paec.com');
    }

    public function test_admin_can_refresh_and_logout(): void
    {
        $token = $this->authenticateAdmin();

        $this->withToken($token)
            ->postJson('/api/v1/admin/refresh')
            ->assertOk()
            ->assertJsonStructure(['access_token']);

        $this->withToken($token)
            ->postJson('/api/v1/admin/logout')
            ->assertOk();
    }

    public function test_admin_change_password_requires_valid_payload(): void
    {
        $this->withAdmin()
            ->postJson('/api/v1/admin/change-password', [])
            ->assertStatus(422);
    }

    public function test_admin_notifications_endpoints(): void
    {
        $this->withAdmin()->getJson('/api/v1/admin/notifications')->assertOk();
        $this->withAdmin()->getJson('/api/v1/admin/notifications/unread-count')->assertOk();
    }

    public function test_admin_dashboard_endpoints(): void
    {
        $this->withAdmin()->getJson('/api/v1/admin/dashboard/stats')->assertOk();
        $this->withAdmin()->getJson('/api/v1/admin/dashboard/recent-activities')->assertOk();
        $this->withAdmin()->getJson('/api/v1/admin/dashboard-stats')->assertOk();
    }

    public function test_admin_events_module(): void
    {
        $token = $this->authenticateSuperAdmin();

        $this->withToken($token)->getJson('/api/v1/events')->assertOk();
        $this->withToken($token)->getJson('/api/v1/events/stats')->assertOk();
        $this->withToken($token)->getJson('/api/v1/events/fun-stats')->assertOk();
        $this->withToken($token)->getJson("/api/v1/events/{$this->event->uuid}")->assertOk();
        $this->withToken($token)->getJson("/api/v1/events/{$this->event->uuid}/locations")->assertOk();
        $this->withToken($token)->getJson("/api/v1/events/{$this->event->uuid}/blocked-dates")->assertOk();
        $this->withToken($token)->getJson(
            "/api/v1/events/{$this->event->uuid}/ticket-calendar?" . http_build_query([
                'year' => now()->year,
                'month' => now()->month,
            ]),
        )->assertOk();
    }

    public function test_admin_user_management_module(): void
    {
        $this->withAdmin()->getJson('/api/v1/users')->assertOk();
        $this->withAdmin()->getJson('/api/v1/admin-users')->assertOk();
        $this->withAdmin()->getJson('/api/v1/admin-users/available-roles')->assertOk();
        $this->withAdmin()->getJson('/api/v1/roles')->assertOk();
        $this->withAdmin()->getJson('/api/v1/permissions')->assertOk();
    }

    public function test_admin_catalog_module(): void
    {
        $this->withAdmin()->getJson('/api/v1/categories')->assertOk();
        $this->withAdmin()->getJson('/api/v1/event-sections')->assertOk();
        $this->withAdmin()->getJson('/api/v1/venues')->assertOk();
        $this->withAdmin()->getJson('/api/v1/schedules')->assertOk();
        $this->withAdmin()->getJson('/api/v1/schedule-times')->assertOk();
        $this->withAdmin()->getJson('/api/v1/event-tickets')->assertOk();
        $this->withAdmin()->getJson('/api/v1/promo-codes')->assertOk();
        $this->withAdmin()->getJson('/api/v1/venue-seats')->assertOk();
    }

    public function test_admin_transactions_and_tickets_module(): void
    {
        $this->withAdmin()->getJson('/api/v1/transactions')->assertOk();
        $this->withAdmin()->getJson('/api/v1/tickets')->assertOk();
        $this->withAdmin()->getJson('/api/v1/ticket-seats')->assertOk();
        $this->withAdmin()->getJson('/api/v1/ticket-coupons')->assertOk();
    }

    public function test_admin_analytics_module(): void
    {
        $this->withAdmin()->getJson('/api/v1/analytics/stats')->assertOk();
        $this->withAdmin()->getJson('/api/v1/analytics/events')->assertOk();
        $this->withAdmin()->getJson('/api/v1/analytics/revenue-by-event-pie')->assertOk();
        $this->withAdmin()->getJson('/api/v1/analytics/customer-type-pie')->assertOk();
        $this->withAdmin()->getJson(
            '/api/v1/analytics/transaction-revenue-series?' . http_build_query([
                'granularity' => 'daily',
                'date_from' => now()->subDays(7)->toDateString(),
                'date_to' => now()->toDateString(),
            ]),
        )->assertOk();
    }

    public function test_admin_cms_module(): void
    {
        $this->withAdmin()->getJson('/api/v1/cms/pages')->assertOk();
        $this->withAdmin()->getJson('/api/v1/cms/footer')->assertOk();
    }

    public function test_admin_settings_module(): void
    {
        $this->withAdmin()->getJson('/api/v1/payment-gateway-rate-settings')->assertOk();
        $this->withAdmin()->getJson('/api/v1/default-payment-methods-settings')->assertOk();
    }

    public function test_key_admin_routes_reject_unauthenticated_requests(): void
    {
        $protectedRoutes = [
            '/api/v1/admin/me',
            '/api/v1/events',
            '/api/v1/users',
            '/api/v1/transactions',
            '/api/v1/cms/pages',
            '/api/v1/analytics/stats',
        ];

        foreach ($protectedRoutes as $uri) {
            $this->getJson($uri)->assertUnauthorized();
        }
    }

    public function test_all_admin_get_routes_respond_without_server_error(): void
    {
        $token = $this->authenticateSuperAdmin();

        $routes = ApiRouteInspector::routes('GET', $this->routePlaceholders)
            ->filter(fn (array $route) => $route['auth'] === 'admin');

        $this->assertNotEmpty($routes);

        foreach ($routes as $route) {
            $response = $this->withToken($token)->json('GET', '/' . $route['uri']);
            $status = $response->getStatusCode();
            $label = ApiRouteInspector::formatRouteLabel($route);

            $this->assertTrue(
                ApiRouteInspector::isAcceptableSmokeStatus($status, 'GET', 'admin', true),
                "{$label} returned unacceptable status {$status}",
            );
        }
    }

    public function test_all_admin_mutation_routes_require_authentication(): void
    {
        $routes = ApiRouteInspector::routes(null, $this->routePlaceholders)
            ->filter(fn (array $route) => in_array($route['method'], ['POST', 'PUT', 'PATCH', 'DELETE'], true))
            ->filter(fn (array $route) => $route['auth'] === 'admin');

        $this->assertNotEmpty($routes);

        foreach ($routes as $route) {
            $response = $this->json($route['method'], '/' . $route['uri']);
            $label = ApiRouteInspector::formatRouteLabel($route);

            $this->assertSame(401, $response->getStatusCode(), "{$label} should return 401 when unauthenticated");
        }
    }

    public function test_all_admin_mutation_routes_do_not_server_error_when_authenticated(): void
    {
        $token = $this->authenticateSuperAdmin();

        $routes = ApiRouteInspector::routes(null, $this->routePlaceholders)
            ->filter(fn (array $route) => in_array($route['method'], ['POST', 'PUT', 'PATCH', 'DELETE'], true))
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

    private function withAdmin(): static
    {
        return $this->withToken($this->authenticateSuperAdmin());
    }
}
