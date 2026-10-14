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
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PaynamicsGatewayTest extends TestCase
{
    use RefreshDatabase;

    private const TEST_QUERY_URL = 'https://payin.payserv.net/paygate/transactions/query';
    private const LIVE_HOST = 'https://payin.live-paynamics.test';
    private const LIVE_QUERY_URL = self::LIVE_HOST . '/paygate/transactions/query';

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config([
            'services.paynamics.testing_url' => 'https://payin.payserv.net',
            'services.paynamics.production_url' => null,
        ]);

        $role = Role::create(['name' => 'Payments Admin', 'code' => GeneralConstants::ROLES['ADMIN']['name']]);
        $permission = Permission::firstOrCreate(
            ['code' => 'payments'],
            ['name' => 'Payments', 'available_access' => ['view', 'create', 'update', 'delete']],
        );
        foreach (['view', 'create', 'update'] as $access) {
            RolePermission::create([
                'role_uuid' => $role->uuid,
                'permission_uuid' => $permission->uuid,
                'access' => 'payments-' . $access,
            ]);
        }
        $admin = AdminUser::create([
            'role_uuid' => $role->uuid,
            'email' => 'payments@test.com',
            'password' => 'password123',
            'first_name' => 'Payment',
            'last_name' => 'Admin',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);
        $this->adminToken = auth('admin')->login($admin);
    }

    /**
     * @param array<string, string> $overrides
     */
    private function postPaynamics(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $this->adminToken])
            ->postJson('/api/v1/payment-gateways', array_merge([
                'name' => 'Paynamics',
                'provider' => 'paynamics',
                'gateway_id' => 'paynamics',
                'public_key' => 'MERCHANT000123',
                'secret_key' => 'mkey-secret-value',
                'api_username' => 'paec-api-user',
                'api_password' => 'paec-api-password',
                'payment_channels' => ['gcash', 'bdo'],
            ], $overrides));
    }

    /**
     * @param array<string, string> $payload
     */
    private function putGateway(string $uuid, array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $this->adminToken])
            ->putJson("/api/v1/payment-gateways/{$uuid}", $payload);
    }

    #[Test]
    public function test_credentials_are_detected_as_testing_and_stored_encrypted(): void
    {
        Http::fake([self::TEST_QUERY_URL => Http::response(['response_code' => 'GR004', 'response_message' => 'Transaction not found'])]);

        $this->postPaynamics()
            ->assertStatus(201)
            ->assertJsonPath('data.provider', 'paynamics')
            ->assertJsonPath('data.environment', 'testing')
            ->assertJsonPath('data.api_username_preview', 'pae••••••••user')
            ->assertJsonPath('data.api_password_preview', '••••••••word')
            ->assertJsonPath('data.payment_channels', ['gcash', 'bdo'])
            ->assertJsonMissingPath('data.api_password');

        Http::assertSent(function (Request $request) {
            $signature = hash('sha512', 'MERCHANT000123' . $request['request_id'] . $request['org_trxid2'] . 'mkey-secret-value');

            return $request->url() === self::TEST_QUERY_URL
                && $request->hasHeader('Authorization', 'Basic ' . base64_encode('paec-api-user:paec-api-password'))
                && $request['signature'] === $signature
                && strlen($request['request_id']) <= 32;
        });

        $raw = DB::table('payment_gateways')->first();
        $this->assertNotSame('paec-api-password', $raw->api_password);
        $this->assertSame('paec-api-password', PaymentGateway::first()->api_password);
    }

    #[Test]
    public function at_least_one_known_payment_channel_is_required(): void
    {
        $this->postPaynamics(['payment_channels' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['payment_channels' => 'Select at least one payment option enabled on your Paynamics account.']);

        $this->postPaynamics(['payment_channels' => ['gcash', 'cliqq']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['payment_channels.1']);

        Http::assertNothingSent();
    }

    #[Test]
    public function live_credentials_are_detected_on_the_configured_production_host(): void
    {
        config(['services.paynamics.production_url' => self::LIVE_HOST . '/']);
        Http::fake([
            self::TEST_QUERY_URL => Http::response('Unauthorized', 401),
            self::LIVE_QUERY_URL => Http::response(['response_code' => 'GR004']),
        ]);

        $this->postPaynamics()
            ->assertStatus(201)
            ->assertJsonPath('data.environment', 'production');
    }

    #[Test]
    public function credentials_rejected_everywhere_are_refused(): void
    {
        config(['services.paynamics.production_url' => self::LIVE_HOST]);
        Http::fake([
            self::TEST_QUERY_URL => Http::response('Unauthorized', 401),
            self::LIVE_QUERY_URL => Http::response('Forbidden', 403),
        ]);

        $this->postPaynamics()
            ->assertStatus(422)
            ->assertJsonValidationErrors(['api_password' => 'Paynamics did not accept this API username and password.']);

        $this->assertDatabaseCount('payment_gateways', 0);
    }

    #[Test]
    public function the_error_explains_the_missing_production_url_when_only_testing_was_checked(): void
    {
        Http::fake([self::TEST_QUERY_URL => Http::response('Unauthorized', 401)]);

        $response = $this->postPaynamics()->assertStatus(422);

        $this->assertStringContainsString('PAYNAMICS_PRODUCTION_URL', $response->json('errors.api_password.0'));
    }

    #[Test]
    public function an_invalid_authentication_code_is_reported_as_rejected_credentials(): void
    {
        config(['services.paynamics.production_url' => self::LIVE_HOST]);
        Http::fake([
            self::TEST_QUERY_URL => Http::response(['response_code' => 'GR153', 'response_message' => 'Invalid Authentication'], 400),
            self::LIVE_QUERY_URL => Http::response(['response_code' => 'GR153', 'response_message' => 'Invalid Authentication'], 400),
        ]);

        $this->postPaynamics()
            ->assertStatus(422)
            ->assertJsonValidationErrors(['api_password' => 'Paynamics did not accept this API username and password.']);
    }

    #[Test]
    public function a_firewall_block_asks_for_the_server_ip_to_be_whitelisted(): void
    {
        config(['services.paynamics.production_url' => self::LIVE_HOST]);
        Http::fake([
            self::TEST_QUERY_URL => Http::response('<html>Access denied</html>', 403),
            self::LIVE_QUERY_URL => Http::response('<html>Access denied</html>', 403),
        ]);

        $this->postPaynamics()
            ->assertStatus(422)
            ->assertJsonValidationErrors(['api_password' => 'whitelist this server\'s public IP address']);

        $this->assertDatabaseCount('payment_gateways', 0);
    }

    #[Test]
    public function a_non_paygate_page_is_not_mistaken_for_accepted_credentials(): void
    {
        Http::fake([self::TEST_QUERY_URL => Http::response('<html>Please verify you are a human</html>', 200)]);

        $this->postPaynamics()
            ->assertStatus(422)
            ->assertJsonValidationErrors(['api_password' => 'whitelist this server\'s public IP address']);
    }

    #[Test]
    public function a_signature_failure_points_at_the_merchant_key(): void
    {
        Http::fake([self::TEST_QUERY_URL => Http::response(['response_code' => 'GR036', 'response_message' => 'Signature Verification failed'])]);

        $this->postPaynamics()
            ->assertStatus(422)
            ->assertJsonValidationErrors(['secret_key']);
    }

    #[Test]
    public function unreachable_paynamics_saves_the_gateway_as_unverified(): void
    {
        config(['services.paynamics.production_url' => self::LIVE_HOST]);
        Http::fake([
            self::TEST_QUERY_URL => fn () => throw new ConnectionException('timed out'),
            self::LIVE_QUERY_URL => Http::response('Bad gateway', 502),
        ]);

        $this->postPaynamics()
            ->assertStatus(201)
            ->assertJsonPath('data.environment', null);
    }

    #[Test]
    public function other_providers_do_not_store_api_credentials(): void
    {
        $this->postPaynamics([
            'name' => 'PayMongo',
            'provider' => 'paymongo',
            'gateway_id' => 'paymongo',
            'public_key' => 'pk_test_1234567890abcdef',
            'secret_key' => 'sk_test_abcdefghijklmnop9876',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.api_username_preview', null);

        $this->assertNull(PaymentGateway::first()->api_username);
        Http::assertNothingSent();
    }

    #[Test]
    public function switching_a_gateway_to_paynamics_requires_and_checks_the_api_credentials(): void
    {
        $gateway = PaymentGateway::create([
            'name' => 'Gateway',
            'provider' => 'paymongo',
            'environment' => 'testing',
            'gateway_id' => 'gateway',
            'public_key' => 'pk_test_1234567890abcdef',
            'secret_key' => 'sk_test_abcdefghijklmnop9876',
            'status' => 'active',
        ]);

        $this->putGateway($gateway->uuid, ['provider' => 'paynamics'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['api_username', 'api_password']);

        Http::fake([self::TEST_QUERY_URL => Http::response(['response_code' => 'GR004'])]);
        $this->putGateway($gateway->uuid, [
            'provider' => 'paynamics',
            'public_key' => 'MERCHANT000123',
            'secret_key' => 'mkey-secret-value',
            'api_username' => 'paec-api-user',
            'api_password' => 'paec-api-password',
        ])
            ->assertOk()
            ->assertJsonPath('data.environment', 'testing');

        $this->assertSame('paec-api-user', $gateway->fresh()->api_username);
    }

    #[Test]
    public function blank_api_fields_keep_the_stored_credentials_and_skip_the_check(): void
    {
        $gateway = PaymentGateway::create([
            'name' => 'Paynamics',
            'provider' => 'paynamics',
            'environment' => 'testing',
            'gateway_id' => 'paynamics',
            'public_key' => 'MERCHANT000123',
            'secret_key' => 'mkey-secret-value',
            'api_username' => 'paec-api-user',
            'api_password' => 'paec-api-password',
            'status' => 'active',
        ]);

        $this->putGateway($gateway->uuid, [
            'provider' => 'paynamics',
            'name' => 'Paynamics Main',
            'api_username' => '',
            'api_password' => ' ',
            'payment_channels' => ['bpi', 'billease'],
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Paynamics Main')
            ->assertJsonPath('data.environment', 'testing')
            ->assertJsonPath('data.payment_channels', ['bpi', 'billease']);

        Http::assertNothingSent();
        $this->assertSame('paec-api-password', $gateway->fresh()->api_password);
    }

    #[Test]
    public function a_paybiz_wallet_id_can_be_saved_changed_and_cleared(): void
    {
        Http::fake([self::TEST_QUERY_URL => Http::response(['response_code' => 'GR004'])]);

        $uuid = $this->postPaynamics(['paybiz_wallet_id' => 'PBW-0001_main'])
            ->assertStatus(201)
            ->assertJsonPath('data.paybiz_wallet_id', 'PBW-0001_main')
            ->json('data.uuid');

        $this->putGateway($uuid, ['paybiz_wallet_id' => 'PBW-0002'])
            ->assertOk()
            ->assertJsonPath('data.paybiz_wallet_id', 'PBW-0002');

        $this->putGateway($uuid, ['paybiz_wallet_id' => ''])
            ->assertOk()
            ->assertJsonPath('data.paybiz_wallet_id', null);
        $this->assertNull(PaymentGateway::find($uuid)->paybiz_wallet_id);
    }

    #[Test]
    public function a_paybiz_wallet_id_with_spaces_or_symbols_is_refused(): void
    {
        $message = 'The Paybiz wallet ID may only contain letters, numbers, dashes and underscores.';

        $this->postPaynamics(['paybiz_wallet_id' => 'PBW 0001'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['paybiz_wallet_id' => $message]);

        $gateway = PaymentGateway::create([
            'name' => 'Paynamics',
            'provider' => 'paynamics',
            'environment' => 'testing',
            'gateway_id' => 'paynamics',
            'public_key' => 'MERCHANT000123',
            'secret_key' => 'mkey-secret-value',
            'api_username' => 'paec-api-user',
            'api_password' => 'paec-api-password',
            'status' => 'active',
        ]);
        $this->putGateway($gateway->uuid, ['paybiz_wallet_id' => 'wallet#1'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['paybiz_wallet_id' => $message]);
    }

    #[Test]
    public function switching_away_from_paynamics_clears_the_api_credentials(): void
    {
        $gateway = PaymentGateway::create([
            'name' => 'Paynamics',
            'provider' => 'paynamics',
            'environment' => 'testing',
            'gateway_id' => 'paynamics',
            'public_key' => 'MERCHANT000123',
            'secret_key' => 'mkey-secret-value',
            'api_username' => 'paec-api-user',
            'api_password' => 'paec-api-password',
            'payment_channels' => ['gcash'],
            'paybiz_wallet_id' => 'PBW-0001',
            'status' => 'active',
        ]);

        $this->putGateway($gateway->uuid, [
            'provider' => 'paymongo',
            'public_key' => 'pk_live_1234567890abcdef',
            'secret_key' => 'sk_live_abcdefghijklmnop9876',
        ])
            ->assertOk()
            ->assertJsonPath('data.environment', 'production')
            ->assertJsonPath('data.payment_channels', null)
            ->assertJsonPath('data.paybiz_wallet_id', null);

        $gateway->refresh();
        $this->assertNull($gateway->api_username);
        $this->assertNull($gateway->api_password);
        $this->assertNull($gateway->payment_channels);
        $this->assertNull($gateway->paybiz_wallet_id);
    }
}
