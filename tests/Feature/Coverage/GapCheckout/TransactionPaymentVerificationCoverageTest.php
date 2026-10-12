<?php

namespace Tests\Feature\Coverage\GapCheckout;

use App\Constants\GeneralConstants;
use App\Jobs\SuccessPaymentProcess;
use App\Models\AdminUser;
use App\Models\Event;
use App\Models\EventTicket;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\Ticket;
use App\Models\Transaction;
use App\Models\User;
use App\Services\MetaPixelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TransactionPaymentVerificationCoverageTest extends TestCase
{
    use RefreshDatabase;
    use GapCheckoutFixtures;

    private const PAYPAL = 'https://api-m.sandbox.paypal.com';

    private User $customer;
    private Event $event;
    private EventTicket $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->configurePaypal();

        $this->customer = $this->createCustomer();
        $this->event = $this->createEvent();
        $this->ticket = $this->createEventTicket($this->event);
    }

    private function complete(Transaction $transaction)
    {
        return $this->actingAs($this->customer, 'api')
            ->postJson("/api/v1/customer/transactions/{$transaction->uuid}/complete");
    }

    private function cancel(Transaction $transaction)
    {
        return $this->actingAs($this->customer, 'api')
            ->postJson("/api/v1/customer/transactions/{$transaction->uuid}/cancel");
    }

    /**
     * @param array<string, mixed> $routes
     */
    private function fakePaypal(array $routes): void
    {
        Http::fake(array_merge([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'token']),
        ], $routes));
    }

    private function paypalOrder(string $id, string $status): array
    {
        return ['id' => $id, 'status' => $status, 'purchase_units' => []];
    }

    private function createTicketFor(Transaction $transaction): Ticket
    {
        return Ticket::create([
            'user_uuid' => $transaction->user_uuid,
            'transaction_uuid' => $transaction->uuid,
            'event_uuid' => $transaction->event_uuid,
            'event_ticket_uuid' => $this->ticket->uuid,
            'ticket_number' => 'T-' . uniqid(),
            'attendee_name' => 'Attendee',
            'attendee_email' => 'attendee@test.com',
            'status' => GeneralConstants::TICKET_STATUSES['PENDING'],
            'price' => 500,
            'discount' => 0,
        ]);
    }

    #[Test]
    public function adminsMayVerifyAnyCustomersPayment(): void
    {
        $role = Role::create(['name' => 'Payments Admin', 'code' => GeneralConstants::ROLES['ADMIN']['name']]);
        $permission = Permission::firstOrCreate(
            ['code' => 'transactions'],
            ['name' => 'Transactions', 'available_access' => ['view', 'execute']],
        );
        RolePermission::create([
            'role_uuid' => $role->uuid,
            'permission_uuid' => $permission->uuid,
            'access' => 'transactions-execute',
        ]);
        $admin = AdminUser::create([
            'role_uuid' => $role->uuid,
            'email' => 'payments-admin@test.com',
            'password' => 'password123',
            'first_name' => 'Pay',
            'last_name' => 'Admin',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);
        $transaction = $this->createTransaction($this->customer, $this->event, [
            'payment_status' => Transaction::PAYMENT_STATUS['PAID'],
        ]);

        $this->withHeaders(['Authorization' => 'Bearer ' . auth('admin')->login($admin)])
            ->postJson("/api/v1/transactions/{$transaction->uuid}/verify-payment")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Payment already completed')
            ->assertJsonPath('transaction.uuid', $transaction->uuid);

        Http::assertNothingSent();
    }

    #[Test]
    public function verifyingAPendingFreeOrderSkipsTheGateway(): void
    {
        $transaction = $this->createTransaction($this->customer, $this->event, [
            'payment_provider' => 'free',
            'payment_id' => null,
            'total_amount' => 0,
            'sub_total' => 0,
        ]);

        $this->complete($transaction)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('payment_status', 'paid')
            ->assertJsonPath('transaction.uuid', $transaction->uuid)
            ->assertJsonPath('message', '🎉 Congratulations! You\'ve successfully claimed your free ticket! Enjoy the event!');

        Http::assertNothingSent();
    }

    #[Test]
    public function verifyingReturns400WhenTheGatewayStatusCannotBeRead(): void
    {
        $transaction = $this->createTransaction($this->customer, $this->event, ['payment_id' => 'ORDER-DOWN']);
        $this->fakePaypal([
            'api-m.sandbox.paypal.com/v2/checkout/orders/ORDER-DOWN' => Http::response(['name' => 'INTERNAL_SERVER_ERROR'], 500),
        ]);

        $this->complete($transaction)
            ->assertStatus(400)
            ->assertExactJson(['success' => false, 'message' => 'Unable to verify payment status']);

        $this->assertSame(Transaction::PAYMENT_STATUS['PENDING'], $transaction->fresh()->payment_status);
    }

    #[Test]
    public function anApprovedPaypalOrderIsCapturedAndMarkedPaid(): void
    {
        Bus::fake([SuccessPaymentProcess::class]);
        $this->useEmptyCheckoutHandlerRegistry();
        $transaction = $this->createTransaction($this->customer, $this->event, ['payment_id' => 'ORDER-OK']);
        $this->fakePaypal([
            'api-m.sandbox.paypal.com/v2/checkout/orders/ORDER-OK' => Http::response($this->paypalOrder('ORDER-OK', 'APPROVED')),
            'api-m.sandbox.paypal.com/v2/checkout/orders/ORDER-OK/capture' => Http::response(
                $this->paypalOrder('ORDER-OK', 'COMPLETED') + ['capture_ref' => 'CAP-1'],
                201,
            ),
        ]);

        $this->complete($transaction)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('payment_status', 'COMPLETED')
            ->assertJsonPath('message', 'Payment completed successfully');

        $fresh = $transaction->fresh();
        $this->assertSame(Transaction::PAYMENT_STATUS['PAID'], $fresh->payment_status);
        $this->assertSame('CAP-1', $fresh->payment_data['capture_ref']);
        Bus::assertDispatchedSync(SuccessPaymentProcess::class, fn (SuccessPaymentProcess $job) => $job->transactionUuid === $transaction->uuid);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/ORDER-OK/capture'));
    }

    #[Test]
    public function aCaptureWithoutACompletedStatusIsRecheckedWithPaypal(): void
    {
        Bus::fake([SuccessPaymentProcess::class]);
        $this->useEmptyCheckoutHandlerRegistry();
        $transaction = $this->createTransaction($this->customer, $this->event, ['payment_id' => 'ORDER-SLOW']);
        $this->fakePaypal([
            'api-m.sandbox.paypal.com/v2/checkout/orders/ORDER-SLOW' => Http::sequence()
                ->push($this->paypalOrder('ORDER-SLOW', 'APPROVED'))
                ->push($this->paypalOrder('ORDER-SLOW', 'COMPLETED')),
            'api-m.sandbox.paypal.com/v2/checkout/orders/ORDER-SLOW/capture' => Http::response($this->paypalOrder('ORDER-SLOW', 'PENDING'), 201),
        ]);

        $this->complete($transaction)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('payment_status', 'COMPLETED');

        $this->assertSame(Transaction::PAYMENT_STATUS['PAID'], $transaction->fresh()->payment_status);
        Http::assertSentCount(4);
    }

    #[Test]
    public function aDeclinedPaypalCaptureReturnsTheGatewayError(): void
    {
        Log::spy();
        $transaction = $this->createTransaction($this->customer, $this->event, ['payment_id' => 'ORDER-DECLINED']);
        $this->fakePaypal([
            'api-m.sandbox.paypal.com/v2/checkout/orders/ORDER-DECLINED' => Http::response($this->paypalOrder('ORDER-DECLINED', 'APPROVED')),
            'api-m.sandbox.paypal.com/v2/checkout/orders/ORDER-DECLINED/capture' => Http::response([
                'name' => 'UNPROCESSABLE_ENTITY',
                'details' => [['issue' => 'INSTRUMENT_DECLINED', 'description' => 'The instrument presented was declined.']],
            ], 422),
        ]);

        $response = $this->complete($transaction);

        $response->assertStatus(400)
            ->assertJsonPath('success', false)
            ->assertJsonPath('payment_status', 'APPROVED')
            ->assertJsonPath('transaction.uuid', $transaction->uuid);
        $this->assertNotEmpty($response->json('message'));
        $this->assertSame(Transaction::PAYMENT_STATUS['PENDING'], $transaction->fresh()->payment_status);
        Log::shouldHaveReceived('error')->with('PayPal payment capture failed', Mockery::on(
            fn (array $context) => $context['payment_id'] === 'ORDER-DECLINED'
        ));
    }

    #[Test]
    public function aVoidedPaypalOrderCancelsTheTransactionAndItsTickets(): void
    {
        $transaction = $this->createTransaction($this->customer, $this->event, ['payment_id' => 'ORDER-VOID']);
        $this->createTicketFor($transaction);
        $this->fakePaypal([
            'api-m.sandbox.paypal.com/v2/checkout/orders/ORDER-VOID' => Http::response($this->paypalOrder('ORDER-VOID', 'VOIDED')),
        ]);

        $this->complete($transaction)
            ->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('payment_status', 'VOIDED')
            ->assertJsonPath('message', 'Payment failed')
            ->assertJsonPath('transaction.payment_status', Transaction::PAYMENT_STATUS['FAILED']);

        $fresh = $transaction->fresh();
        $this->assertSame(Transaction::STATUS['CANCELLED'], $fresh->status);
        $this->assertSame(Transaction::ORDER_STATUS['CANCELLED'], $fresh->order_status);
        $this->assertSame('VOIDED', $fresh->payment_data['status']);
        $this->assertSame(0, Ticket::where('transaction_uuid', $transaction->uuid)->count());
    }

    #[Test]
    public function verifyingReturns500WhenTheProviderIsUnsupported(): void
    {
        Log::spy();
        $transaction = $this->createTransaction($this->customer, $this->event, ['payment_provider' => 'cash']);

        $this->complete($transaction)
            ->assertStatus(500)
            ->assertExactJson(['success' => false, 'message' => 'Payment completion failed']);

        Log::shouldHaveReceived('error')->with('Payment completion failed', Mockery::on(
            fn (array $context) => $context['error'] === 'Unsupported payment provider: cash'
        ));
    }

    #[Test]
    public function paidOrdersCannotBeCancelled(): void
    {
        $transaction = $this->createTransaction($this->customer, $this->event, [
            'payment_status' => Transaction::PAYMENT_STATUS['PAID'],
        ]);

        $this->cancel($transaction)
            ->assertStatus(409)
            ->assertExactJson([
                'success' => false,
                'payment_status' => Transaction::PAYMENT_STATUS['PAID'],
                'message' => 'This order is already paid and cannot be cancelled here.',
            ]);
    }

    #[Test]
    public function cancellingAnAlreadyCancelledOrderIsIdempotent(): void
    {
        $transaction = $this->createTransaction($this->customer, $this->event, [
            'payment_status' => Transaction::PAYMENT_STATUS['CANCELLED'],
        ]);

        $this->cancel($transaction)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('transaction.uuid', $transaction->uuid)
            ->assertJsonPath('message', 'Payment cancelled successfully');

        Http::assertNothingSent();
    }

    #[Test]
    public function cancellingASessionThatWasAlreadyPaidCompletesTheOrderInstead(): void
    {
        Bus::fake([SuccessPaymentProcess::class]);
        $this->useEmptyCheckoutHandlerRegistry();
        $gateway = $this->createGateway('paymongo');
        $transaction = $this->createTransaction($this->customer, $this->event, [
            'payment_provider' => 'paymongo',
            'payment_gateway_uuid' => $gateway->uuid,
            'payment_id' => 'cs_gap_paid',
        ]);
        Http::fake([
            'api.paymongo.com/v1/checkout_sessions/cs_gap_paid/expire' => Http::response([
                'errors' => [['code' => 'resource_state_error', 'detail' => 'The checkout session has already been paid.']],
            ], 400),
            'api.paymongo.com/v1/checkout_sessions/cs_gap_paid' => Http::response([
                'data' => [
                    'id' => 'cs_gap_paid',
                    'attributes' => [
                        'checkout_url' => 'https://checkout.paymongo.com/cs_gap_paid',
                        'status' => 'active',
                        'line_items' => [['amount' => 50000, 'currency' => 'PHP']],
                        'payment_intent' => ['id' => 'pi_gap', 'attributes' => ['status' => 'succeeded']],
                    ],
                ],
            ]),
        ]);

        $this->cancel($transaction)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Payment completed successfully');

        $this->assertSame(Transaction::PAYMENT_STATUS['PAID'], $transaction->fresh()->payment_status);
        Bus::assertDispatchedSync(SuccessPaymentProcess::class);
    }

    #[Test]
    public function cancellingStillSucceedsWhenTheGatewayCannotBeReached(): void
    {
        Log::spy();
        $unsupported = $this->createGateway('stripe');
        $transaction = $this->createTransaction($this->customer, $this->event, [
            'payment_provider' => 'paymongo',
            'payment_gateway_uuid' => $unsupported->uuid,
            'payment_id' => 'cs_gap_unreachable',
        ]);

        $this->cancel($transaction)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('transaction.payment_status', Transaction::PAYMENT_STATUS['CANCELLED']);

        Http::assertNothingSent();
        Log::shouldHaveReceived('warning')->with('Failed to expire gateway checkout', Mockery::on(
            fn (array $context) => $context['transaction_uuid'] === $transaction->uuid
        ));
    }

    #[Test]
    public function cancellingForADeletedAccountTracksThePixelEventWithoutUserData(): void
    {
        $transaction = $this->createTransaction($this->customer, $this->event, ['payment_provider' => 'paypal']);
        $this->customer->delete();
        $this->mock(MetaPixelService::class, function ($mock) use ($transaction) {
            $mock->shouldReceive('trackPaymentCancellation')
                ->once()
                ->with(Mockery::on(fn (Transaction $tracked) => $tracked->uuid === $transaction->uuid), []);
        });

        $this->cancel($transaction)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('transaction.user', null);

        $this->assertSame(Transaction::PAYMENT_STATUS['CANCELLED'], $transaction->fresh()->payment_status);
    }

    #[Test]
    public function cancellingSucceedsWhenPixelTrackingFails(): void
    {
        Log::spy();
        $transaction = $this->createTransaction($this->customer, $this->event);
        $ticket = $this->createTicketFor($transaction);
        $this->mock(MetaPixelService::class, function ($mock) {
            $mock->shouldReceive('trackPaymentCancellation')->andThrow(new \RuntimeException('Pixel down'));
        });

        $this->cancel($transaction)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Payment cancelled successfully');

        $this->assertSame(Transaction::PAYMENT_STATUS['CANCELLED'], $transaction->fresh()->payment_status);
        $this->assertNull(Ticket::find($ticket->uuid));
        Log::shouldHaveReceived('error')->with('Failed to track Meta Pixel cancellation event', [
            'transaction_uuid' => $transaction->uuid,
            'error' => 'Pixel down',
        ]);
    }
}
