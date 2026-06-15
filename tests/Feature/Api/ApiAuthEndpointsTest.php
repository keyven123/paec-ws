<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Concerns\AuthenticatesApiUsers;
use Tests\TestCase;

class ApiAuthEndpointsTest extends TestCase
{
    use AuthenticatesApiUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedApiDatabase();
    }

    public function test_admin_login_with_invalid_credentials_is_rejected(): void
    {
        $this->postJson('/api/v1/admin/login', [
            'email' => 'admin@paec.com',
            'password' => 'wrong-password',
        ])->assertStatus(422);
    }

    public function test_customer_login_with_invalid_credentials_is_rejected(): void
    {
        $this->postJson('/api/v1/login', [
            'email' => 'customer@paec.com',
            'password' => 'wrong-password',
        ])->assertStatus(422);
    }

    public function test_customer_register_requires_valid_payload(): void
    {
        $this->postJson('/api/v1/register', [])
            ->assertStatus(422);
    }

    public function test_customer_can_refresh_token(): void
    {
        $token = $this->authenticateCustomer();

        $this->withToken($token)
            ->postJson('/api/v1/refresh')
            ->assertOk()
            ->assertJsonStructure(['access_token']);
    }

    public function test_admin_can_refresh_token(): void
    {
        $token = $this->authenticateAdmin();

        $this->withToken($token)
            ->postJson('/api/v1/admin/refresh')
            ->assertOk()
            ->assertJsonStructure(['access_token']);
    }

    public function test_customer_can_logout(): void
    {
        $token = $this->authenticateCustomer();

        $this->withToken($token)
            ->postJson('/api/v1/logout')
            ->assertOk();
    }

    public function test_admin_can_logout(): void
    {
        $token = $this->authenticateAdmin();

        $this->withToken($token)
            ->postJson('/api/v1/admin/logout')
            ->assertOk();
    }

    public function test_password_reset_initiate_requires_payload(): void
    {
        $this->postJson('/api/v1/password_reset/initiate', [])
            ->assertStatus(422);
    }
}
