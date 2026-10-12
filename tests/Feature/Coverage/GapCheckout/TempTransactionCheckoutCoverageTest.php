<?php

namespace Tests\Feature\Coverage\GapCheckout;

use App\Jobs\SuccessPaymentProcess;
use App\Models\Event;
use App\Models\EventTicket;
use App\Models\TempTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Support\OrganizationPaymentMethods;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TempTransactionCheckoutCoverageTest extends TestCase
{
    use RefreshDatabase;
    use GapCheckoutFixtures;

    private const BASE = '/api/v1/customer/temp-transactions';

    private User $customer;
    private Event $event;
    private EventTicket $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->customer = $this->createCustomer();
        $this->event = $this->createEvent();
        $this->ticket = $this->createEventTicket($this->event);
    }

    private function maxedOutPromoCode(): string
    {
        return $this->createPromoCode([
            'is_unlimited' => false,
            'max_use' => 1,
            'used_count' => 1,
        ])->uuid;
    }

    #[Test]
    public function checkoutRejectsAHoldOwnedByAnotherCustomer(): void
    {
        $temp = $this->createTempTransaction($this->createCustomer(), $this->ticket);

        $this->actingAs($this->customer, 'api')->postJson(self::BASE . '/checkout', [
            'temp_transaction_uuid' => $temp->uuid,
            'payment_provider' => 'paymongo',
        ])->assertUnprocessable()
            ->assertJsonPath('errors.temp_transaction_uuid.0', 'This order does not belong to your account.');

        $this->assertSame(0, Transaction::count());
    }

    #[Test]
    public function checkoutDropsAPromoCodeThatReachedItsUsageLimit(): void
    {
        $temp = $this->createTempTransaction($this->customer, $this->ticket, [
            'promo_code_uuid' => $this->maxedOutPromoCode(),
            'promo_code_discount' => 50,
            'total_amount' => 450,
        ]);

        $this->actingAs($this->customer, 'api')->postJson(self::BASE . '/checkout', [
            'temp_transaction_uuid' => $temp->uuid,
            'payment_provider' => 'paymongo',
        ])->assertNotFound()
            ->assertExactJson([
                'promo_code_applied' => false,
                'success' => false,
                'message' => 'Promo code reached its maximum usage. Promo code has been removed.',
            ]);

        $fresh = $temp->fresh();
        $this->assertNull($fresh->promo_code_uuid);
        $this->assertEquals(0, $fresh->promo_code_discount);
        $this->assertEquals(500, $fresh->total_amount);
        $this->assertSame(0, Transaction::count());
    }

    #[Test]
    public function checkoutOfAZeroTotalHoldConfirmsTheOrderWithoutAPaymentProvider(): void
    {
        Bus::fake([SuccessPaymentProcess::class]);
        $temp = $this->createTempTransaction($this->customer, $this->ticket, [
            'sub_total' => 0,
            'total_amount' => 0,
        ], ['price' => 0, 'total_amount' => 0]);

        $response = $this->actingAs($this->customer, 'api')->postJson(self::BASE . '/checkout', [
            'temp_transaction_uuid' => $temp->uuid,
            'payment_provider' => 'paymongo',
        ]);

        $transactionUuid = $response->json('transaction.uuid');
        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('payment', ['provider' => 'free', 'status' => 'paid'])
            ->assertJsonPath('transaction.payment_status', Transaction::PAYMENT_STATUS['PAID'])
            ->assertJsonPath('redirect_url', config('app.frontend_url') . '/payment/success/' . $transactionUuid);

        $this->assertDatabaseHas('transactions', [
            'uuid' => $transactionUuid,
            'order_status' => Transaction::ORDER_STATUS['CONFIRMED'],
        ]);
        $this->assertNull(TempTransaction::find($temp->uuid));
        Bus::assertDispatched(SuccessPaymentProcess::class);
        Http::assertNothingSent();
    }

    #[Test]
    public function gatewayCheckoutFallsBackToDefaultPaymongoMethodsWhenTheOrganizerEnabledNone(): void
    {
        $organization = $this->createOrganization([['name' => 'paypal', 'value' => true, 'provider' => 'paypal']]);
        $this->event->update(['organization_uuid' => $organization->uuid]);
        $gateway = $this->createGateway('paymongo');
        $this->event->paymentGateways()->sync([$gateway->uuid]);
        $temp = $this->createTempTransaction($this->customer, $this->ticket, ['organization_uuid' => $organization->uuid]);

        Http::fake([
            'api.paymongo.com/v1/checkout_sessions' => Http::response([
                'data' => [
                    'id' => 'cs_gap_default',
                    'attributes' => [
                        'checkout_url' => 'https://checkout.paymongo.com/cs_gap_default',
                        'status' => 'active',
                        'line_items' => [['amount' => 50000, 'currency' => 'PHP']],
                    ],
                ],
            ]),
        ]);

        $this->actingAs($this->customer, 'api')->postJson(self::BASE . '/checkout', [
            'temp_transaction_uuid' => $temp->uuid,
            'payment_gateway_uuid' => $gateway->uuid,
        ])->assertCreated()
            ->assertJsonPath('payment.provider', 'paymongo')
            ->assertJsonPath('redirect_url', 'https://checkout.paymongo.com/cs_gap_default');

        $expected = OrganizationPaymentMethods::defaultPaymongoCheckoutApiTypes();
        $this->assertNotEmpty($expected);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.paymongo.com/v1/checkout_sessions'
            && $request['data']['attributes']['payment_method_types'] === $expected);
    }

    #[Test]
    public function paypalCardCheckoutDropsAPromoCodeThatReachedItsUsageLimit(): void
    {
        $temp = $this->createTempTransaction($this->customer, $this->ticket, [
            'promo_code_uuid' => $this->maxedOutPromoCode(),
            'promo_code_discount' => 50,
            'total_amount' => 450,
        ]);

        $this->actingAs($this->customer, 'api')->postJson(self::BASE . '/checkout-paypal-card', [
            'temp_transaction_uuid' => $temp->uuid,
        ])->assertNotFound()
            ->assertJsonPath('promo_code_applied', false)
            ->assertJsonPath('message', 'Promo code reached its maximum usage. Promo code has been removed.');

        $this->assertNull($temp->fresh()->promo_code_uuid);
        $this->assertEquals(500, $temp->fresh()->total_amount);
    }

    #[Test]
    public function paypalCardCheckoutRejectsFreeHolds(): void
    {
        $temp = $this->createTempTransaction($this->customer, $this->ticket, ['sub_total' => 0, 'total_amount' => 0]);

        $this->actingAs($this->customer, 'api')->postJson(self::BASE . '/checkout-paypal-card', [
            'temp_transaction_uuid' => $temp->uuid,
        ])->assertStatus(400)
            ->assertExactJson([
                'success' => false,
                'message' => 'This transaction does not require card payment. Please use the free checkout.',
            ]);

        $this->assertSame(0, Transaction::count());
    }

    #[Test]
    public function paypalCardCheckoutRollsBackWhenPaypalRejectsTheOrder(): void
    {
        $this->configurePaypal();
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'token']),
            'api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response(['name' => 'INTERNAL_SERVER_ERROR'], 500),
        ]);
        $temp = $this->createTempTransaction($this->customer, $this->ticket);

        $response = $this->actingAs($this->customer, 'api')->postJson(self::BASE . '/checkout-paypal-card', [
            'temp_transaction_uuid' => $temp->uuid,
        ]);

        $response->assertStatus(500)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Checkout failed. Please try again.');
        $this->assertStringStartsWith('Payment creation failed: PayPal API Error:', $response->json('error'));
        $this->assertSame(0, Transaction::count());
        $this->assertNotNull(TempTransaction::find($temp->uuid));
    }

    #[Test]
    public function placeOrderRunsTheFulfilmentJobWhenNoCheckoutHandlerIsRegistered(): void
    {
        Bus::fake([SuccessPaymentProcess::class]);
        $this->useEmptyCheckoutHandlerRegistry();
        $temp = $this->createTempTransaction($this->customer, $this->ticket);

        $response = $this->actingAs($this->customer, 'api')->postJson(self::BASE . '/place-order', [
            'temp_transaction_uuid' => $temp->uuid,
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('transaction.payment_status', Transaction::PAYMENT_STATUS['PAID']);
        $transactionUuid = $response->json('transaction.uuid');
        Bus::assertDispatchedSync(SuccessPaymentProcess::class, fn (SuccessPaymentProcess $job) => $job->transactionUuid === $transactionUuid);
        $this->assertNull(TempTransaction::find($temp->uuid));
    }

    #[Test]
    public function placeOrderReturns500AndKeepsTheHoldWhenCheckoutFails(): void
    {
        Log::spy();
        $this->failingRepository('checkout');
        $temp = $this->createTempTransaction($this->customer, $this->ticket);

        $this->actingAs($this->customer, 'api')->postJson(self::BASE . '/place-order', [
            'temp_transaction_uuid' => $temp->uuid,
        ])->assertStatus(500)
            ->assertExactJson([
                'success' => false,
                'message' => 'Checkout failed. Please try again.',
                'error' => 'Database unavailable',
            ]);

        $this->assertNotNull(TempTransaction::find($temp->uuid));
        Log::shouldHaveReceived('error')->with('Place order failed', \Mockery::type('array'));
    }

    #[Test]
    public function freeCheckoutQueuesTheFulfilmentJobWhenNoCheckoutHandlerIsRegistered(): void
    {
        Bus::fake([SuccessPaymentProcess::class]);
        $this->useEmptyCheckoutHandlerRegistry();
        $temp = $this->createTempTransaction($this->customer, $this->ticket, ['sub_total' => 0, 'total_amount' => 0], ['price' => 0, 'total_amount' => 0]);

        $response = $this->actingAs($this->customer, 'api')->postJson(self::BASE . '/checkout-free', [
            'temp_transaction_uuid' => $temp->uuid,
        ]);

        $response->assertCreated()
            ->assertJsonPath('payment', ['provider' => 'free', 'status' => 'paid']);
        $transactionUuid = $response->json('transaction.uuid');
        Bus::assertDispatched(SuccessPaymentProcess::class, fn (SuccessPaymentProcess $job) => $job->transactionUuid === $transactionUuid);
        $this->assertNull(TempTransaction::find($temp->uuid));
    }

    #[Test]
    public function freeCheckoutReturns500AndKeepsTheHoldWhenCheckoutFails(): void
    {
        Log::spy();
        $this->failingRepository('checkout');
        $temp = $this->createTempTransaction($this->customer, $this->ticket, ['sub_total' => 0, 'total_amount' => 0]);

        $this->actingAs($this->customer, 'api')->postJson(self::BASE . '/checkout-free', [
            'temp_transaction_uuid' => $temp->uuid,
        ])->assertStatus(500)
            ->assertExactJson([
                'success' => false,
                'message' => 'Free checkout failed. Please try again.',
                'error' => 'Database unavailable',
            ]);

        $this->assertNotNull(TempTransaction::find($temp->uuid));
        $this->assertSame(0, Transaction::count());
        Log::shouldHaveReceived('error')->with('Free checkout failed', \Mockery::type('array'));
    }
}
