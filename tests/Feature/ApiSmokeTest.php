<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Concerns\AuthenticatesApiUsers;
use Tests\TestCase;

class ApiSmokeTest extends TestCase
{
    use AuthenticatesApiUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedApiDatabase();
    }

    public function test_health_endpoint(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonStructure(['status', 'database', 'timestamp']);
    }

    public function test_api_info_endpoint(): void
    {
        $this->getJson('/api')
            ->assertOk()
            ->assertJsonPath('name', 'PAEC');
    }

    public function test_admin_login_and_me(): void
    {
        $token = $this->authenticateAdmin();

        $this->withToken($token)
            ->getJson('/api/v1/admin/me')
            ->assertOk();
    }

    public function test_customer_login_and_me(): void
    {
        $token = $this->authenticateCustomer();

        $this->withToken($token)
            ->getJson('/api/v1/me')
            ->assertOk();
    }

    public function test_public_endpoints(): void
    {
        $this->getJson('/api/v1/public/categories')->assertOk();
        $this->getJson('/api/v1/public/events')->assertOk();
    }

    public function test_admin_protected_endpoints(): void
    {
        $token = $this->authenticateSuperAdmin();

        $this->withToken($token)->getJson('/api/v1/admin/dashboard/stats')->assertOk();
        $this->withToken($token)->getJson('/api/v1/events')->assertOk();
        $this->withToken($token)->getJson('/api/v1/promo-codes')->assertOk();
        $this->withToken($token)->getJson('/api/v1/users')->assertOk();
        $this->withToken($token)->getJson('/api/v1/admin-users')->assertOk();
        $this->withToken($token)->getJson('/api/v1/roles')->assertOk();
        $this->withToken($token)->getJson('/api/v1/transactions')->assertOk();
        $this->withToken($token)->getJson('/api/v1/analytics/stats')->assertOk();
        $this->withToken($token)->getJson('/api/v1/cms/pages')->assertOk();
        $this->withToken($token)->getJson('/api/v1/cms/footer')->assertOk();
    }

    public function test_customer_protected_endpoints(): void
    {
        $token = $this->authenticateCustomer();

        $this->withToken($token)->getJson('/api/v1/customer/my-tickets')->assertOk();
        $this->withToken($token)->getJson('/api/v1/customer/my-coupons')->assertOk();
        $this->withToken($token)->getJson('/api/v1/customer/my-transactions')->assertOk();
    }

    public function test_unauthenticated_admin_routes_are_rejected(): void
    {
        $this->getJson('/api/v1/events')->assertUnauthorized();
    }
}
