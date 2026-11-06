<?php

namespace Tests\Feature;

use App\Constants\GeneralConstants;
use App\Models\Event;
use App\Models\EventTicket;
use App\Models\PaymentGateway;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GatewayCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private const GUEST_EMAIL = 'guest.buyer@example.com';
    private const SESSION_ID = 'cs_test_gateway_session';

    private Event $event;
    private EventTicket $eventTicket;
    private PaymentGateway $paymongo;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        Role::create([
            'name' => 'Customer',
            'code' => GeneralConstants::ROLES['CUSTOMER']['name'],
        ]);

        $this->event = Event::create([
            'event_name' => 'Neon Arcade Zone',
            'event_description' => 'Arcade',
            'contact_email' => 'contact@event.com',
            'event_type' => Event::EVENT_TYPES['DAILY'],
            'schedule_type' => Event::SCHEDULE_TYPES['DAILY'],
            'event_config' => Event::EVENT_CONFIGS['OPEN_TICKET'],
            'status' => GeneralConstants::EVENT_STATUSES['PUBLISHED'],
        ]);

        $this->eventTicket = EventTicket::create([
            'event_uuid' => $this->event->uuid,
            'code' => 'day_pass',
            'name' => 'Day Pass',
            'description' => 'Day Pass',
            'price' => 500,
            'max_ticket' => 100,
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        $this->paymongo = $this->createGateway('paymongo', 'testing', 'pk_test_gateway', 'sk_test_gateway');
        $this->event->paymentGateways()->sync([$this->paymongo->uuid]);
    }

    private function createGateway(string $provider, ?string $environment, string $publicKey, string $secretKey): PaymentGateway
    {
        return PaymentGateway::create([
            'name' => ucfirst($provider),
            'provider' => $provider,
            'environment' => $environment,
            'gateway_id' => $provider . '-' . uniqid(),
            'public_key' => $publicKey,
            'secret_key' => $secretKey,
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);
    }

    private function createGuestTempTransaction(): string
    {
        return $this->postJson('/api/v1/public/temp-transactions/guest', [
            'event_uuid' => $this->event->uuid,
            'tickets' => [[
                'event_ticket_uuid' => $this->eventTicket->uuid,
                'quantity' => 2,
                'valid_until' => now()->addDays(3)->toDateString(),
                'seats' => [],
            ]],
            'guest' => [
                'first_name' => 'Guest',
                'last_name' => 'Buyer',
                'address' => '123 Test St',
                'email' => self::GUEST_EMAIL,
                'phone_number' => '09121231234',
            ],
        ])->assertStatus(201)->json('uuid');
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function fakePayMongo(string $sessionStatus = 'active', ?string $intentStatus = null, array $extra = []): void
    {
        $session = fn (string $status, ?string $intent) => [
            'data' => [
                'id' => self::SESSION_ID,
                'attributes' => array_filter([
                    'checkout_url' => 'https://checkout.paymongo.com/' . self::SESSION_ID,
                    'status' => $status,
                    'line_items' => [['amount' => 100000, 'currency' => 'PHP']],
                    'payment_intent' => $intent ? ['id' => 'pi_test', 'attributes' => ['status' => $intent]] : null,
                ]),
            ],
        ];

        Http::fake(array_merge([
            'api.paymongo.com/v1/checkout_sessions/' . self::SESSION_ID . '/expire' => Http::response($session('expired', null)),
            'api.paymongo.com/v1/checkout_sessions/' . self::SESSION_ID => Http::response($session($sessionStatus, $intentStatus)),
            'api.paymongo.com/v1/checkout_sessions' => Http::response($session('active', null)),
        ], $extra));
    }

    private function startGuestCheckout(string $tempUuid, ?string $gatewayUuid = null)
    {
        return $this->postJson('/api/v1/public/temp-transactions/guest/checkout', [
            'temp_transaction_uuid' => $tempUuid,
            'email' => self::GUEST_EMAIL,
            'payment_gateway_uuid' => $gatewayUuid ?? $this->paymongo->uuid,
        ]);
    }

    #[Test]
    public function guest_checkout_uses_the_activity_gateway_keys_and_returns_the_hosted_page(): void
    {
        $this->fakePayMongo();
        $tempUuid = $this->createGuestTempTransaction();

        $response = $this->startGuestCheckout($tempUuid)
            ->assertStatus(201)
            ->assertJsonPath('redirect_url', 'https://checkout.paymongo.com/' . self::SESSION_ID)
            ->assertJsonPath('payment.provider', 'paymongo');

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://api.paymongo.com/v1/checkout_sessions'
                && $request->hasHeader('Authorization', 'Basic ' . base64_encode('sk_test_gateway:'))
                && str_ends_with($request['data']['attributes']['success_url'], '/payment/success/' . Transaction::firstOrFail()->uuid);
        });

        $transaction = Transaction::findOrFail($response->json('transaction.uuid'));
        $this->assertSame($this->paymongo->uuid, $transaction->payment_gateway_uuid);
        $this->assertSame(self::SESSION_ID, $transaction->payment_id);
        $this->assertSame(Transaction::PAYMENT_STATUS['PENDING'], $transaction->payment_status);
    }

    #[Test]
    public function completing_a_paid_session_issues_tickets_once(): void
    {
        $this->fakePayMongo('active', 'succeeded');
        $transactionUuid = $this->startGuestCheckout($this->createGuestTempTransaction())->json('transaction.uuid');

        $complete = fn () => $this->postJson("/api/v1/public/transactions/{$transactionUuid}/guest/complete", [
            'email' => 'GUEST.buyer@example.com',
        ]);

        $complete()
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('transaction.payment_status', 'paid')
            ->assertJsonCount(2, 'tickets');

        $tickets = Ticket::where('transaction_uuid', $transactionUuid)->get();
        $this->assertCount(2, $tickets);
        $this->assertTrue($tickets->every(fn (Ticket $ticket) => $ticket->status === GeneralConstants::TICKET_STATUSES['ACTIVE'] && $ticket->qr_code));
        $this->assertSame(2, (int) $this->eventTicket->fresh()->sold_ticket);

        // Returning to the page again must not fulfil the order twice.
        $complete()->assertOk()->assertJsonPath('success', true)->assertJsonCount(2, 'tickets');
        $this->assertSame(2, (int) $this->eventTicket->fresh()->sold_ticket);
    }

    #[Test]
    public function an_unpaid_session_stays_pending(): void
    {
        $this->fakePayMongo('active');
        $transactionUuid = $this->startGuestCheckout($this->createGuestTempTransaction())->json('transaction.uuid');

        $this->postJson("/api/v1/public/transactions/{$transactionUuid}/guest/complete", ['email' => self::GUEST_EMAIL])
            ->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Payment is still pending on the gateway. No charge was confirmed yet.');

        $this->assertSame(Transaction::PAYMENT_STATUS['PENDING'], Transaction::findOrFail($transactionUuid)->payment_status);
    }

    #[Test]
    public function guests_cannot_verify_or_cancel_someone_elses_order(): void
    {
        $this->fakePayMongo('active', 'succeeded');
        $transactionUuid = $this->startGuestCheckout($this->createGuestTempTransaction())->json('transaction.uuid');

        $this->postJson("/api/v1/public/transactions/{$transactionUuid}/guest/complete", ['email' => 'other@example.com'])
            ->assertNotFound();
        $this->postJson("/api/v1/public/transactions/{$transactionUuid}/guest/cancel", ['email' => 'other@example.com'])
            ->assertNotFound();

        $this->assertSame(Transaction::PAYMENT_STATUS['PENDING'], Transaction::findOrFail($transactionUuid)->payment_status);
    }

    #[Test]
    public function cancelling_expires_the_session_and_releases_the_tickets(): void
    {
        $this->fakePayMongo();
        $transactionUuid = $this->startGuestCheckout($this->createGuestTempTransaction())->json('transaction.uuid');

        $this->postJson("/api/v1/public/transactions/{$transactionUuid}/guest/cancel", ['email' => self::GUEST_EMAIL])
            ->assertOk()
            ->assertJsonPath('success', true);

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/checkout_sessions/' . self::SESSION_ID . '/expire'));
        $this->assertSame(Transaction::PAYMENT_STATUS['CANCELLED'], Transaction::findOrFail($transactionUuid)->payment_status);
        $this->assertSame(0, Ticket::where('transaction_uuid', $transactionUuid)->count());

        // Coming back to the success URL afterwards does not revive the order.
        $this->postJson("/api/v1/public/transactions/{$transactionUuid}/guest/complete", ['email' => self::GUEST_EMAIL])
            ->assertOk()
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function only_active_gateways_set_on_the_activity_can_be_used(): void
    {
        $this->fakePayMongo();
        $tempUuid = $this->createGuestTempTransaction();

        $unlinked = $this->createGateway('paymongo', 'production', 'pk_live_other', 'sk_live_other');
        $this->startGuestCheckout($tempUuid, $unlinked->uuid)
            ->assertStatus(422)
            ->assertJsonValidationErrors('payment_gateway_uuid');

        $inactive = $this->createGateway('paymongo', 'testing', 'pk_test_inactive', 'sk_test_inactive');
        $inactive->update(['status' => GeneralConstants::GENERAL_STATUSES['INACTIVE']]);
        $this->event->paymentGateways()->attach($inactive->uuid);
        $this->startGuestCheckout($tempUuid, $inactive->uuid)
            ->assertStatus(422)
            ->assertJsonValidationErrors('payment_gateway_uuid');

        $this->assertSame(0, Transaction::count());
        Http::assertNothingSent();
    }

    #[Test]
    public function paypal_gateways_use_their_own_credentials_and_environment(): void
    {
        $paypal = $this->createGateway('paypal', 'testing', 'client-id', 'client-secret');
        $this->event->paymentGateways()->sync([$paypal->uuid]);

        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'token']),
            'api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response([
                'id' => 'ORDER-1',
                'status' => 'CREATED',
                'links' => [['rel' => 'approve', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=ORDER-1']],
            ], 201),
        ]);

        $this->startGuestCheckout($this->createGuestTempTransaction(), $paypal->uuid)
            ->assertStatus(201)
            ->assertJsonPath('payment.provider', 'paypal')
            ->assertJsonPath('redirect_url', 'https://www.sandbox.paypal.com/checkoutnow?token=ORDER-1');

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v1/oauth2/token')
            && $request->hasHeader('Authorization', 'Basic ' . base64_encode('client-id:client-secret')));
    }

    #[Test]
    public function customers_cannot_check_out_or_verify_another_customers_order(): void
    {
        $this->fakePayMongo('active', 'succeeded');
        $transactionUuid = $this->startGuestCheckout($this->createGuestTempTransaction())->json('transaction.uuid');

        $other = User::factory()->create([
            'role_uuid' => Role::firstOrFail()->uuid,
            'email' => 'other.customer@example.com',
        ]);
        $token = auth('api')->login($other);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson("/api/v1/customer/transactions/{$transactionUuid}/complete")
            ->assertForbidden();

        $this->assertSame(Transaction::PAYMENT_STATUS['PENDING'], Transaction::findOrFail($transactionUuid)->payment_status);
    }
}
