<?php

namespace Tests\Feature;

use App\Constants\GeneralConstants;
use App\Models\AdminUser;
use App\Models\Category;
use App\Models\Event;
use App\Models\PaymentGateway;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EventPaymentGatewayTest extends TestCase
{
    use RefreshDatabase;

    private string $adminToken;
    private Category $category;
    private PaymentGateway $paymongoTest;
    private PaymentGateway $paymongoLive;
    private PaymentGateway $paypal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminToken = $this->createAdminToken('events', ['view', 'create', 'update']);

        $this->category = Category::create([
            'name' => 'Arcade',
            'code' => 'arcade',
            'type' => Category::TYPES['EVENT'],
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        $this->paymongoTest = $this->createGateway('PayMongo Test', 'paymongo', 'testing', 'paymongo-test');
        $this->paymongoLive = $this->createGateway('PayMongo Live', 'paymongo', 'production', 'paymongo-live');
        $this->paypal = $this->createGateway('PayPal', 'paypal', 'production', 'paypal-live');
    }

    /**
     * @param array<int, string> $accesses
     */
    private function createAdminToken(string $permissionCode, array $accesses, string $email = 'admin@test.com'): string
    {
        $role = Role::create([
            'name' => 'Role ' . $email,
            'code' => GeneralConstants::ROLES['ADMIN']['name'],
        ]);
        $permission = Permission::firstOrCreate(
            ['code' => $permissionCode],
            ['name' => ucfirst($permissionCode), 'available_access' => ['view', 'create', 'update', 'delete']],
        );
        foreach ($accesses as $access) {
            RolePermission::create([
                'role_uuid' => $role->uuid,
                'permission_uuid' => $permission->uuid,
                'access' => "{$permissionCode}-{$access}",
            ]);
        }

        $admin = AdminUser::create([
            'role_uuid' => $role->uuid,
            'email' => $email,
            'password' => 'password123',
            'first_name' => 'Activity',
            'last_name' => 'Admin',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);

        return auth('admin')->login($admin) ?? '';
    }

    private function createGateway(string $name, string $provider, ?string $environment, string $gatewayId, string $status = 'active'): PaymentGateway
    {
        return PaymentGateway::create([
            'name' => $name,
            'provider' => $provider,
            'environment' => $environment,
            'gateway_id' => $gatewayId,
            'public_key' => 'public-' . $gatewayId,
            'secret_key' => 'secret-' . $gatewayId,
            'status' => $status,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function headers(?string $token = null): array
    {
        return ['Authorization' => 'Bearer ' . ($token ?? $this->adminToken)];
    }

    private function createEvent(): Event
    {
        return Event::create([
            'event_name' => 'Neon Arcade Zone',
            'event_description' => 'Arcade',
            'contact_email' => 'inquire@paec.com',
            'event_type' => Event::EVENT_TYPES['DAILY'],
            'schedule_type' => Event::SCHEDULE_TYPES['DAILY'],
            'event_config' => Event::EVENT_CONFIGS['OPEN_TICKET'],
            'slug' => 'neon-arcade-zone',
            'status' => GeneralConstants::EVENT_STATUSES['DRAFT'],
        ]);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function updatePayload(array $overrides = []): array
    {
        return array_merge([
            'event_name' => 'Neon Arcade Zone',
            'event_description' => 'Arcade',
            'contact_email' => 'inquire@paec.com',
            'city' => 'Taguig',
            'category_uuid' => $this->category->uuid,
            'event_type' => Event::EVENT_TYPES['DAILY'],
            'slug' => 'neon-arcade-zone',
        ], $overrides);
    }

    #[Test]
    public function it_saves_payment_gateways_when_creating_an_activity(): void
    {
        $response = $this->withHeaders($this->headers())->postJson('/api/v1/events', [
            'event_name' => 'Neon Arcade Zone',
            'event_description' => 'Arcade',
            'contact_email' => 'inquire@paec.com',
            'city' => 'Taguig',
            'category_uuid' => $this->category->uuid,
            'event_type' => Event::EVENT_TYPES['DAILY'],
            'schedule_type' => Event::SCHEDULE_TYPES['DAILY'],
            'event_config' => Event::EVENT_CONFIGS['OPEN_TICKET'],
            'status' => GeneralConstants::EVENT_STATUSES['DRAFT'],
            'payment_gateway_uuids' => json_encode([$this->paymongoLive->uuid, $this->paypal->uuid]),
        ]);

        $response->assertStatus(201)->assertJsonCount(2, 'data.payment_gateways');

        $event = Event::where('event_name', 'Neon Arcade Zone')->firstOrFail();
        $this->assertEqualsCanonicalizing(
            [$this->paymongoLive->uuid, $this->paypal->uuid],
            $event->paymentGateways()->pluck('payment_gateways.uuid')->all(),
        );
    }

    #[Test]
    public function it_updates_and_clears_payment_gateways_and_returns_them_without_keys(): void
    {
        $event = $this->createEvent();

        $this->withHeaders($this->headers())
            ->putJson("/api/v1/events/{$event->uuid}", $this->updatePayload([
                'payment_gateway_uuids' => json_encode([$this->paymongoTest->uuid]),
            ]))
            ->assertOk()
            ->assertJsonCount(1, 'data.payment_gateways')
            ->assertJsonPath('data.payment_gateways.0.uuid', $this->paymongoTest->uuid)
            ->assertJsonPath('data.payment_gateways.0.environment', 'testing')
            ->assertJsonMissingPath('data.payment_gateways.0.secret_key')
            ->assertJsonMissingPath('data.payment_gateways.0.secret_key_preview');

        $this->withHeaders($this->headers())
            ->getJson("/api/v1/events/{$event->uuid}")
            ->assertOk()
            ->assertJsonPath('data.payment_gateways.0.name', 'PayMongo Test');

        // Omitting the field leaves the selection alone.
        $this->withHeaders($this->headers())
            ->putJson("/api/v1/events/{$event->uuid}", $this->updatePayload())
            ->assertOk();
        $this->assertSame(1, $event->paymentGateways()->count());

        // An empty JSON list clears it.
        $this->withHeaders($this->headers())
            ->putJson("/api/v1/events/{$event->uuid}", $this->updatePayload([
                'payment_gateway_uuids' => '[]',
            ]))
            ->assertOk()
            ->assertJsonCount(0, 'data.payment_gateways');
        $this->assertSame(0, $event->paymentGateways()->count());
    }

    #[Test]
    public function it_allows_only_one_gateway_per_provider(): void
    {
        $event = $this->createEvent();

        $this->withHeaders($this->headers())
            ->putJson("/api/v1/events/{$event->uuid}", $this->updatePayload([
                'payment_gateway_uuids' => json_encode([$this->paymongoTest->uuid, $this->paymongoLive->uuid]),
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['payment_gateway_uuids']);

        $this->assertSame(0, $event->paymentGateways()->count());
    }

    #[Test]
    public function it_rejects_unknown_or_deleted_gateways(): void
    {
        $event = $this->createEvent();
        $this->paypal->delete();

        $this->withHeaders($this->headers())
            ->putJson("/api/v1/events/{$event->uuid}", $this->updatePayload([
                'payment_gateway_uuids' => json_encode([$this->paypal->uuid, 'not-a-uuid']),
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['payment_gateway_uuids.0', 'payment_gateway_uuids.1']);
    }

    #[Test]
    public function activity_editors_can_list_active_gateway_options_without_keys(): void
    {
        $this->createGateway('Paynamics', 'paynamics', null, 'paynamics', 'inactive');

        $this->withHeaders($this->headers())
            ->getJson('/api/v1/payment-gateways/options')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonMissingPath('data.0.public_key')
            ->assertJsonMissingPath('data.0.secret_key')
            ->assertJsonStructure(['data' => [['uuid', 'name', 'provider', 'environment', 'gateway_id', 'status']]]);

        // The full gateway list (with key previews) still needs the Payments permission.
        $this->withHeaders($this->headers())
            ->getJson('/api/v1/payment-gateways')
            ->assertForbidden();
    }

    #[Test]
    public function the_public_activity_page_lists_only_active_gateways_without_keys(): void
    {
        $event = $this->createEvent();
        $event->update(['category_uuid' => $this->category->uuid]);
        $paynamics = $this->createGateway('Paynamics', 'paynamics', null, 'paynamics', 'inactive');
        $event->paymentGateways()->sync([$this->paymongoLive->uuid, $paynamics->uuid]);

        $this->getJson("/api/v1/public/events/{$event->slug}")
            ->assertOk()
            ->assertJsonCount(1, 'data.payment_gateways')
            ->assertJsonPath('data.payment_gateways.0', [
                'uuid' => $this->paymongoLive->uuid,
                'name' => 'PayMongo Live',
                'provider' => 'paymongo',
                'environment' => 'production',
                'payment_channels' => null,
            ]);
    }

    #[Test]
    public function the_public_activity_page_lists_the_enabled_paynamics_channels(): void
    {
        $event = $this->createEvent();
        $event->update(['category_uuid' => $this->category->uuid]);
        $paynamics = $this->createGateway('Paynamics', 'paynamics', 'testing', 'paynamics-test', 'active');
        $paynamics->update(['payment_channels' => ['gcash', 'bdo']]);
        $event->paymentGateways()->sync([$paynamics->uuid]);

        $this->getJson("/api/v1/public/events/{$event->slug}")
            ->assertOk()
            ->assertJsonPath('data.payment_gateways.0.payment_channels', ['gcash', 'bdo']);
    }

    #[Test]
    public function admins_without_activity_or_payment_permissions_cannot_list_options(): void
    {
        $token = $this->createAdminToken('promo-codes', ['view'], 'promo@test.com');

        $this->withHeaders($this->headers($token))
            ->getJson('/api/v1/payment-gateways/options')
            ->assertForbidden();
    }
}
