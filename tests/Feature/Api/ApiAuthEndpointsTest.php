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

    public function test_customer_register_creates_account_without_email_verification(): void
    {
        $response = $this->postJson('/api/v1/register', [
            'first_name' => 'Jane',
            'last_name' => 'Customer',
            'address' => '123 Test Street, Manila',
            'phone_number' => '+639171112233',
            'email' => 'newcustomer@paec.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'terms_accepted' => true,
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['success', 'message', 'access_token', 'user'])
            ->assertJsonPath('user.email', 'newcustomer@paec.com');

        $this->assertDatabaseHas('users', [
            'email' => 'newcustomer@paec.com',
            'first_name' => 'Jane',
            'address_line_1' => '123 Test Street, Manila',
        ]);
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
