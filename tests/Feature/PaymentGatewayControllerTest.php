<?php

namespace Tests\Feature;

use App\Constants\GeneralConstants;
use App\Models\AdminUser;
use App\Models\PaymentGateway;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PaymentGatewayControllerTest extends TestCase
{
    use RefreshDatabase;

    private const SANDBOX_TOKEN_URL = 'https://api-m.sandbox.paypal.com/v1/oauth2/token';
    private const LIVE_TOKEN_URL = 'https://api-m.paypal.com/v1/oauth2/token';

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->adminToken = $this->createAdminToken(['view', 'create', 'update', 'delete']);
    }

    /**
     * @param array<int, string> $accesses
     */
    private function createAdminToken(array $accesses, string $email = 'admin@test.com'): string
    {
        $role = Role::create([
            'name' => 'Admin ' . $email,
            'code' => GeneralConstants::ROLES['ADMIN']['name'],
        ]);

        $permission = Permission::firstOrCreate(
            ['code' => 'payments'],
            ['name' => 'Payments', 'available_access' => ['view', 'create', 'update', 'delete']],
        );

        foreach ($accesses as $access) {
            RolePermission::create([
                'role_uuid' => $role->uuid,
                'permission_uuid' => $permission->uuid,
                'access' => 'payments-' . $access,
            ]);
        }

        $admin = AdminUser::create([
            'role_uuid' => $role->uuid,
            'email' => $email,
            'password' => 'password123',
            'first_name' => 'Payment',
            'last_name' => 'Admin',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);

        return auth('admin')->login($admin) ?? '';
    }

    /**
     * @return array<string, string>
     */
    private function headers(?string $token = null): array
    {
        return ['Authorization' => 'Bearer ' . ($token ?? $this->adminToken)];
    }

    private function createGateway(array $overrides = []): PaymentGateway
    {
        return PaymentGateway::create(array_merge([
            'name' => 'PayMongo',
            'provider' => 'paymongo',
            'environment' => 'testing',
            'gateway_id' => 'paymongo',
            'public_key' => 'pk_test_1234567890abcdef',
            'secret_key' => 'sk_test_abcdefghijklmnop9876',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ], $overrides));
    }

    /**
     * @param array<string, string> $overrides
     */
    private function postGateway(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders($this->headers())->postJson('/api/v1/payment-gateways', array_merge([
            'name' => 'PayMongo',
            'provider' => 'paymongo',
            'gateway_id' => 'paymongo',
            'public_key' => 'pk_test_1234567890abcdef',
            'secret_key' => 'sk_test_abcdefghijklmnop9876',
        ], $overrides));
    }

    #[Test]
    public function it_creates_a_gateway_and_stores_keys_encrypted(): void
    {
        $this->postGateway()
            ->assertStatus(201)
            ->assertJsonPath('data.name', 'PayMongo')
            ->assertJsonPath('data.provider', 'paymongo')
            ->assertJsonPath('data.gateway_id', 'paymongo')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.secret_key_preview', '••••••••9876')
            ->assertJsonPath('data.public_key_preview', 'pk_test_••••••••cdef')
            ->assertJsonMissingPath('data.secret_key')
            ->assertJsonMissingPath('data.public_key');

        $raw = DB::table('payment_gateways')->first();
        $this->assertNotSame('sk_test_abcdefghijklmnop9876', $raw->secret_key);
        $this->assertNotSame('pk_test_1234567890abcdef', $raw->public_key);

        $gateway = PaymentGateway::first();
        $this->assertSame('sk_test_abcdefghijklmnop9876', $gateway->secret_key);
    }

    #[Test]
    public function it_requires_name_provider_id_and_both_keys(): void
    {
        $this->withHeaders($this->headers())
            ->postJson('/api/v1/payment-gateways', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'provider', 'gateway_id', 'public_key', 'secret_key']);
    }

    #[Test]
    public function it_rejects_unsupported_providers(): void
    {
        $this->postGateway(['provider' => 'stripe'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['provider']);
    }

    #[Test]
    public function it_detects_paymongo_environment_from_key_prefixes(): void
    {
        $this->postGateway()
            ->assertStatus(201)
            ->assertJsonPath('data.environment', 'testing');

        $this->postGateway([
            'gateway_id' => 'paymongo-live',
            'public_key' => 'pk_live_1234567890abcdef',
            'secret_key' => 'sk_live_abcdefghijklmnop9876',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.environment', 'production');
    }

    #[Test]
    public function it_rejects_mixed_or_unrecognised_paymongo_keys(): void
    {
        $this->postGateway(['secret_key' => 'sk_live_abcdefghijklmnop9876'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['secret_key']);

        $this->postGateway(['public_key' => 'not-a-paymongo-key', 'secret_key' => 'nope'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['public_key', 'secret_key']);

        $this->assertDatabaseCount('payment_gateways', 0);
    }

    #[Test]
    public function it_detects_paypal_sandbox_credentials(): void
    {
        Http::fake([
            self::SANDBOX_TOKEN_URL => Http::response(['access_token' => 'token'], 200),
            self::LIVE_TOKEN_URL => Http::response(['error' => 'invalid_client'], 401),
        ]);

        $this->postGateway([
            'name' => 'PayPal',
            'provider' => 'paypal',
            'gateway_id' => 'paypal',
            'public_key' => 'AYsq-client-id',
            'secret_key' => 'EGnH-client-secret',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.environment', 'testing');
    }

    #[Test]
    public function it_detects_paypal_live_credentials(): void
    {
        Http::fake([
            self::SANDBOX_TOKEN_URL => Http::response(['error' => 'invalid_client'], 401),
            self::LIVE_TOKEN_URL => Http::response(['access_token' => 'token'], 200),
        ]);

        $this->postGateway([
            'name' => 'PayPal',
            'provider' => 'paypal',
            'gateway_id' => 'paypal',
            'public_key' => 'AYsq-client-id',
            'secret_key' => 'EGnH-client-secret',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.environment', 'production');
    }

    #[Test]
    public function it_rejects_paypal_credentials_that_neither_environment_accepts(): void
    {
        Http::fake([
            self::SANDBOX_TOKEN_URL => Http::response(['error' => 'invalid_client'], 401),
            self::LIVE_TOKEN_URL => Http::response(['error' => 'invalid_client'], 401),
        ]);

        $this->postGateway([
            'provider' => 'paypal',
            'public_key' => 'wrong-id',
            'secret_key' => 'wrong-secret',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['secret_key']);
    }

    #[Test]
    public function it_saves_paypal_as_unverified_when_paypal_is_unreachable(): void
    {
        Http::fake(fn () => throw new ConnectionException('timed out'));

        $this->postGateway([
            'provider' => 'paypal',
            'public_key' => 'AYsq-client-id',
            'secret_key' => 'EGnH-client-secret',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.environment', null);
    }

    #[Test]
    public function paynamics_requires_the_api_username_and_password(): void
    {
        $this->postGateway([
            'name' => 'Paynamics',
            'provider' => 'paynamics',
            'gateway_id' => 'paynamics',
            'public_key' => 'MERCHANT000123',
            'secret_key' => 'mkey-secret-value',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['api_username', 'api_password']);

        Http::assertNothingSent();
    }

    #[Test]
    public function it_rejects_duplicate_gateway_ids(): void
    {
        $this->createGateway();

        $this->postGateway(['name' => 'PayMongo 2'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['gateway_id']);
    }

    #[Test]
    public function it_lists_and_filters_gateways(): void
    {
        $this->createGateway();
        $this->createGateway([
            'name' => 'PayPal',
            'provider' => 'paypal',
            'environment' => 'production',
            'gateway_id' => 'paypal',
            'status' => GeneralConstants::GENERAL_STATUSES['INACTIVE'],
        ]);
        $this->createGateway([
            'name' => 'Paynamics',
            'provider' => 'paynamics',
            'environment' => null,
            'gateway_id' => 'paynamics',
        ]);

        $this->withHeaders($this->headers())
            ->getJson('/api/v1/payment-gateways')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.total', 3);

        $this->withHeaders($this->headers())
            ->getJson('/api/v1/payment-gateways?provider=paypal')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.provider', 'paypal');

        $this->withHeaders($this->headers())
            ->getJson('/api/v1/payment-gateways?environment=testing')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.gateway_id', 'paymongo');

        $this->withHeaders($this->headers())
            ->getJson('/api/v1/payment-gateways?environment=unverified')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.gateway_id', 'paynamics');

        $this->withHeaders($this->headers())
            ->getJson('/api/v1/payment-gateways?q=paypal')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.gateway_id', 'paypal');

        $this->withHeaders($this->headers())
            ->getJson('/api/v1/payment-gateways?status=inactive')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.gateway_id', 'paypal');
    }

    #[Test]
    public function blank_keys_on_update_keep_the_stored_values_and_environment(): void
    {
        $gateway = $this->createGateway();

        $this->withHeaders($this->headers())
            ->putJson("/api/v1/payment-gateways/{$gateway->uuid}", [
                'name' => 'PayMongo Sandbox',
                'gateway_id' => 'paymongo',
                'public_key' => '',
                'secret_key' => '',
                'status' => 'inactive',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'PayMongo Sandbox')
            ->assertJsonPath('data.environment', 'testing')
            ->assertJsonPath('data.status', 'inactive');

        $gateway->refresh();
        $this->assertSame('pk_test_1234567890abcdef', $gateway->public_key);
        $this->assertSame('sk_test_abcdefghijklmnop9876', $gateway->secret_key);
    }

    #[Test]
    public function replacing_keys_re_detects_the_environment(): void
    {
        $gateway = $this->createGateway();

        // Only one key replaced: it must still match the stored one.
        $this->withHeaders($this->headers())
            ->putJson("/api/v1/payment-gateways/{$gateway->uuid}", [
                'secret_key' => 'sk_live_newsecret0000',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['secret_key']);

        $this->withHeaders($this->headers())
            ->putJson("/api/v1/payment-gateways/{$gateway->uuid}", [
                'public_key' => 'pk_live_newpublic1111',
                'secret_key' => 'sk_live_newsecret0000',
            ])
            ->assertOk()
            ->assertJsonPath('data.environment', 'production');

        $this->assertSame('sk_live_newsecret0000', $gateway->refresh()->secret_key);
    }

    #[Test]
    public function it_deletes_a_gateway(): void
    {
        $gateway = $this->createGateway();

        $this->withHeaders($this->headers())
            ->deleteJson("/api/v1/payment-gateways/{$gateway->uuid}")
            ->assertNoContent();

        $this->assertSoftDeleted('payment_gateways', ['uuid' => $gateway->uuid]);
    }

    #[Test]
    public function view_only_admins_cannot_create_gateways(): void
    {
        $token = $this->createAdminToken(['view'], 'viewer@test.com');

        $this->withHeaders($this->headers($token))
            ->postJson('/api/v1/payment-gateways', [
                'name' => 'PayMongo',
                'provider' => 'paymongo',
                'gateway_id' => 'paymongo',
                'public_key' => 'pk_test_1234567890abcdef',
                'secret_key' => 'sk_test_abcdefghijklmnop9876',
            ])
            ->assertForbidden();
    }
}
