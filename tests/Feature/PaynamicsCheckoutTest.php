<?php

namespace Tests\Feature;

use App\Constants\GeneralConstants;
use App\Http\Resources\TransactionResource;
use App\Models\AdminUser;
use App\Models\Event;
use App\Models\EventTicket;
use App\Models\PaymentGateway;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\Transaction;
use App\Notifications\PaymentCancelledNotification;
use App\Notifications\PaymentSuccessfulNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PaynamicsCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private const GUEST_EMAIL = 'guest.buyer@example.com';
    private const CREATE_URL = 'https://payin.payserv.net/paygate/transactions/';
    private const QUERY_URL = 'https://payin.payserv.net/paygate/transactions/query';
    private const PAYMENT_LINK = 'https://payin.payserv.net/paygate/pay/abc123';
    private const MERCHANT_ID = 'MERCHANT000123';
    private const MERCHANT_KEY = 'mkey-secret-value';

    private Event $event;
    private EventTicket $eventTicket;
    private PaymentGateway $paynamics;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config([
            'services.paynamics.testing_url' => 'https://payin.payserv.net',
            'services.paynamics.production_url' => null,
        ]);

        Role::create(['name' => 'Customer', 'code' => GeneralConstants::ROLES['CUSTOMER']['name']]);

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

        $this->paynamics = $this->createGateway('testing');
        $this->event->paymentGateways()->sync([$this->paynamics->uuid]);
    }

    private function createGateway(?string $environment): PaymentGateway
    {
        return PaymentGateway::create([
            'name' => 'Paynamics',
            'provider' => 'paynamics',
            'environment' => $environment,
            'gateway_id' => 'paynamics-' . uniqid(),
            'public_key' => self::MERCHANT_ID,
            'secret_key' => self::MERCHANT_KEY,
            'api_username' => 'paec-api-user',
            'api_password' => 'paec-api-password',
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

    private function checkout(?string $channel = 'gcash', ?string $gatewayUuid = null)
    {
        return $this->postJson('/api/v1/public/temp-transactions/guest/checkout', array_filter([
            'temp_transaction_uuid' => $this->createGuestTempTransaction(),
            'email' => self::GUEST_EMAIL,
            'payment_gateway_uuid' => $gatewayUuid ?? $this->paynamics->uuid,
            'payment_channel' => $channel,
        ]));
    }

    private function createdLink(): array
    {
        return [
            'request_id' => 'ignored',
            'response_id' => 'RESP-1',
            'response_code' => 'GR033',
            'response_message' => 'Transaction Pending',
            'payment_action_info' => self::PAYMENT_LINK,
        ];
    }

    /**
     * Query response as returned by the PayGate test server: a flat result for the original request_id.
     */
    private function queryResult(string $requestId, string $code, string $message = 'message'): array
    {
        return [
            'response_code' => $code,
            'response_message' => $message,
            'response_advise' => '',
            'signature' => str_repeat('a', 128),
            'response_id' => '68732685650597616',
            'merchant_id' => self::MERCHANT_ID,
            'expiry_limit' => '2026-10-12T20:33:35.000+08:00',
            'request_id' => $requestId,
            'payment_action_info' => '',
            'timestamp' => '2026-10-12T20:03:51.285+08:00',
        ];
    }

    /**
     * Starts a GCash checkout and then answers queries with the given sale result.
     */
    private function checkedOutTransaction(string $code = 'GR033', string $message = 'message'): Transaction
    {
        $this->resetHttpFake();
        Http::fake(function (Request $request) use ($code, $message) {
            if ($request->url() === self::CREATE_URL) {
                return Http::response($this->createdLink());
            }

            return Http::response($this->queryResult($request['org_trxid2'], $code, $message));
        });

        $uuid = $this->checkout()->assertStatus(201)->json('transaction.uuid');

        return Transaction::findOrFail($uuid);
    }

    /**
     * Http::fake callbacks stack, so start from a clean client before answering differently.
     */
    private function resetHttpFake(): void
    {
        Http::swap(new HttpFactory(app('events')));
        Http::preventStrayRequests();
    }

    private function complete(Transaction $transaction)
    {
        return $this->postJson("/api/v1/public/transactions/{$transaction->uuid}/guest/complete", ['email' => self::GUEST_EMAIL]);
    }

    #[Test]
    public function checkout_creates_a_signed_paynamics_link_and_redirects_to_it(): void
    {
        Http::fake([self::CREATE_URL => Http::response($this->createdLink())]);

        $response = $this->checkout('gcash')
            ->assertStatus(201)
            ->assertJsonPath('payment.provider', 'paynamics')
            ->assertJsonPath('payment.checkout_url', self::PAYMENT_LINK)
            ->assertJsonPath('redirect_url', self::PAYMENT_LINK);

        $transaction = Transaction::findOrFail($response->json('transaction.uuid'));
        $this->assertSame('paynamics', $transaction->payment_provider);
        $this->assertSame($this->paynamics->uuid, $transaction->payment_gateway_uuid);
        $this->assertSame($transaction->payment_order_id, $transaction->payment_id);
        $this->assertSame(Transaction::PAYMENT_STATUS['PENDING'], $transaction->payment_status);
        $this->assertNull($transaction->paybiz_wallet_id);

        Http::assertSent(function (Request $request) use ($transaction) {
            $trx = $request['transaction'];
            $customer = $request['customer_info'];
            $amount = number_format((float) $transaction->total_amount, 2, '.', '');

            $expectedTrxSignature = hash('sha512', self::MERCHANT_ID . $transaction->payment_order_id
                . $trx['notification_url'] . $trx['response_url'] . $trx['cancel_url']
                . 'wallet' . 'url_link' . '' . 'single_pay' . '' . '' . '' . $amount . 'PHP' . '' . '1' . '1'
                . self::MERCHANT_KEY);
            $expectedCustomerSignature = hash('sha512', 'Guest' . 'Buyer' . '' . self::GUEST_EMAIL . '' . '+639121231234' . '' . self::MERCHANT_KEY);

            return $request->url() === self::CREATE_URL
                && $request->hasHeader('Authorization', 'Basic ' . base64_encode('paec-api-user:paec-api-password'))
                && $trx['request_id'] === $transaction->payment_order_id
                && $trx['pmethod'] === 'wallet'
                && $trx['pchannel'] === 'gc'
                && $trx['payment_action'] === 'url_link'
                && ! isset($trx['trx_type'])
                && $trx['amount'] === $amount
                && str_ends_with($trx['notification_url'], '/api/v1/payments/paynamics/notification')
                && str_ends_with($trx['response_url'], '/payment/success/' . $transaction->uuid)
                && str_ends_with($trx['cancel_url'], '/payment/cancel/' . $transaction->uuid)
                && $trx['signature'] === $expectedTrxSignature
                && $customer['signature'] === $expectedCustomerSignature
                && $request['billing_info']['billing_country'] === 'PH'
                && $request['order_details']['totalorderamount'] === $amount
                && $request['order_details']['orders'][0]['totalprice'] === $amount;
        });
    }

    #[Test]
    public function the_paybiz_wallet_used_at_checkout_is_kept_on_the_transaction(): void
    {
        $this->paynamics->update(['paybiz_wallet_id' => 'BIZ652HKB3']);
        Http::fake([self::CREATE_URL => Http::response($this->createdLink())]);

        $uuid = $this->checkout()->assertStatus(201)->json('transaction.uuid');

        Http::assertSent(fn (Request $request) => $request['settlement_information'][0]['biz_wallet_id'] === 'BIZ652HKB3');

        $this->paynamics->update(['paybiz_wallet_id' => 'BIZ-CHANGED']);
        $this->assertSame('BIZ652HKB3', Transaction::findOrFail($uuid)->paybiz_wallet_id);
    }

    #[Test]
    public function only_admins_see_the_paybiz_wallet_of_a_transaction(): void
    {
        $transaction = new Transaction(['paybiz_wallet_id' => 'BIZ652HKB3']);

        $this->assertArrayNotHasKey('paybiz_wallet_id', (new TransactionResource($transaction))->resolve(request()));

        $admin = AdminUser::create([
            'role_uuid' => Role::create(['name' => 'Admin', 'code' => 'admin_wallet_test'])->uuid,
            'email' => 'wallet.admin@test.com',
            'password' => 'password123',
            'first_name' => 'Wallet',
            'last_name' => 'Admin',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);
        $this->actingAs($admin, 'admin');

        $this->assertSame('BIZ652HKB3', (new TransactionResource($transaction))->resolve(request())['paybiz_wallet_id']);
    }

    #[Test]
    public function card_payments_are_sent_as_a_sale(): void
    {
        Http::fake([self::CREATE_URL => Http::response(['payment_action_info' => ['url' => self::PAYMENT_LINK]])]);

        $this->checkout('card')
            ->assertStatus(201)
            ->assertJsonPath('redirect_url', self::PAYMENT_LINK);

        Http::assertSent(fn (Request $request) => $request['transaction']['pmethod'] === 'creditcard'
            && $request['transaction']['pchannel'] === ''
            && $request['transaction']['trx_type'] === 'sale');
    }

    #[Test]
    public function a_payment_channel_is_required_and_must_be_offered(): void
    {
        $this->checkout(null)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['payment_channel' => 'Choose how you want to pay with Paynamics.']);

        $this->checkout('cliqq')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['payment_channel']);

        $this->assertSame(0, Transaction::count());
        Http::assertNothingSent();
    }

    #[Test]
    public function only_channels_enabled_on_the_gateway_can_be_used(): void
    {
        $this->paynamics->update(['payment_channels' => ['gcash', 'bdo']]);

        $this->checkout('card')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['payment_channel' => 'This payment option is not available right now. Please choose another one.']);

        $this->assertSame(0, Transaction::count());
        Http::assertNothingSent();
    }

    #[Test]
    public function a_channel_paynamics_has_not_enabled_is_reported_to_the_customer(): void
    {
        foreach (['GR150' => 'Payment Method or Payment Channel not Allowed', 'GR009' => 'Transaction type not allowed'] as $code => $message) {
            $this->resetHttpFake();
            Http::fake([self::CREATE_URL => Http::response(['response_code' => $code, 'response_message' => $message], 400)]);

            $this->checkout('card')
                ->assertStatus(422)
                ->assertJsonValidationErrors(['payment_channel' => 'This payment option is not available right now. Please choose another one.']);
        }

        $this->assertSame(0, Transaction::count());
    }

    #[Test]
    public function a_paynamics_rejection_rolls_the_order_back(): void
    {
        Http::fake([self::CREATE_URL => Http::response([
            'response_code' => 'GR035',
            'response_message' => 'Merchant Host I.P. not registered',
        ])]);

        $this->checkout()
            ->assertStatus(500)
            ->assertJsonPath('message', 'Checkout failed. Please try again.')
            ->assertJsonPath('error', 'Payment creation failed: Paynamics: Merchant Host I.P. not registered');

        $this->assertSame(0, Transaction::count());
    }

    #[Test]
    public function an_unreachable_paynamics_fails_the_checkout(): void
    {
        Http::fake([self::CREATE_URL => fn () => throw new ConnectionException('timed out')]);

        $this->checkout()
            ->assertStatus(500)
            ->assertJsonPath('error', 'Payment creation failed: Paynamics could not be reached. Please try again.');
    }

    #[Test]
    public function live_gateways_need_the_production_url(): void
    {
        $live = $this->createGateway('production');
        $this->event->paymentGateways()->sync([$live->uuid]);

        $this->checkout('gcash', $live->uuid)
            ->assertStatus(500)
            ->assertJsonPath('error', 'The Paynamics production URL is not configured. Set PAYNAMICS_PRODUCTION_URL.');

        config(['services.paynamics.production_url' => 'https://payin.live-paynamics.test/']);
        Http::fake(['payin.live-paynamics.test/paygate/transactions/' => Http::response($this->createdLink())]);

        $this->checkout('gcash', $live->uuid)->assertStatus(201);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://payin.live-paynamics.test/paygate/transactions/');
    }

    #[Test]
    public function a_successful_sale_is_marked_paid_and_issues_the_tickets(): void
    {
        $transaction = $this->checkedOutTransaction('GR001', 'Transaction Successful');

        $this->complete($transaction)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('payment_status', 'paid')
            ->assertJsonCount(2, 'tickets');

        $this->assertSame(Transaction::PAYMENT_STATUS['PAID'], $transaction->fresh()->payment_status);
        $this->assertSame(2, Ticket::where('transaction_uuid', $transaction->uuid)->count());

        Http::assertSent(fn (Request $request) => $request->url() === self::QUERY_URL
            && $request['org_trxid2'] === $transaction->payment_order_id
            && $request['request_id'] !== $transaction->payment_order_id
            && $request['signature'] === hash('sha512', self::MERCHANT_ID . $request['request_id'] . $transaction->payment_order_id . self::MERCHANT_KEY));
    }

    #[Test]
    public function a_pending_sale_stays_pending(): void
    {
        $transaction = $this->checkedOutTransaction('GR033');

        $this->complete($transaction)
            ->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Payment is still pending');

        $this->assertSame(Transaction::PAYMENT_STATUS['PENDING'], $transaction->fresh()->payment_status);
    }

    #[Test]
    public function failed_and_cancelled_sales_cancel_the_order(): void
    {
        foreach (['GR003', 'GR053'] as $code) {
            $transaction = $this->checkedOutTransaction($code);

            $this->complete($transaction)
                ->assertOk()
                ->assertJsonPath('success', false)
                ->assertJsonPath('message', 'Payment failed');

            $this->assertSame(Transaction::PAYMENT_STATUS['FAILED'], $transaction->fresh()->payment_status);
        }
    }

    #[Test]
    public function an_unrecognised_code_is_never_treated_as_paid_or_failed(): void
    {
        $transaction = $this->checkedOutTransaction('GR034', 'Card Stolen');

        $this->complete($transaction)
            ->assertStatus(400)
            ->assertJsonPath('message', 'Unable to verify payment status');

        $this->assertSame(Transaction::PAYMENT_STATUS['PENDING'], $transaction->fresh()->payment_status);
    }

    #[Test]
    public function the_query_calls_own_success_code_is_not_mistaken_for_the_sale(): void
    {
        $transaction = $this->checkedOutTransaction();
        $this->resetHttpFake();
        Http::fake([self::QUERY_URL => Http::response([
            'request_id' => 'Q-QUERY',
            'response_code' => 'GR001',
            'response_message' => 'Transaction Successful',
        ])]);

        $this->complete($transaction)->assertStatus(400);

        $this->assertSame(Transaction::PAYMENT_STATUS['PENDING'], $transaction->fresh()->payment_status);
    }

    #[Test]
    public function query_errors_leave_the_order_pending(): void
    {
        $transaction = $this->checkedOutTransaction();
        $this->resetHttpFake();
        Http::fake([self::QUERY_URL => Http::sequence()
            ->push('Server error', 500)
            ->pushFailedConnection()]);

        $this->complete($transaction)->assertStatus(400);
        $this->complete($transaction)->assertStatus(400);

        $this->assertSame(Transaction::PAYMENT_STATUS['PENDING'], $transaction->fresh()->payment_status);
    }

    #[Test]
    public function cancelling_honours_a_sale_that_was_already_paid(): void
    {
        $transaction = $this->checkedOutTransaction('GR001');

        $this->postJson("/api/v1/public/transactions/{$transaction->uuid}/guest/cancel", ['email' => self::GUEST_EMAIL])
            ->assertOk()
            ->assertJsonPath('message', 'Payment completed successfully');

        $this->assertSame(Transaction::PAYMENT_STATUS['PAID'], $transaction->fresh()->payment_status);
    }

    #[Test]
    public function cancelling_an_unpaid_sale_releases_the_order(): void
    {
        $transaction = $this->checkedOutTransaction('GR033');

        $this->postJson("/api/v1/public/transactions/{$transaction->uuid}/guest/cancel", ['email' => self::GUEST_EMAIL])
            ->assertOk()
            ->assertJsonPath('message', 'Payment cancelled successfully');

        $this->assertSame(Transaction::PAYMENT_STATUS['CANCELLED'], $transaction->fresh()->payment_status);
        $this->assertSame(0, Ticket::where('transaction_uuid', $transaction->uuid)->count());
    }

    #[Test]
    public function the_buyer_is_emailed_when_the_gateway_fails_the_payment(): void
    {
        Notification::fake();
        $transaction = $this->checkedOutTransaction('GR003');

        $this->complete($transaction)->assertJsonPath('message', 'Payment failed');

        Notification::assertSentTo(
            $transaction->user,
            PaymentCancelledNotification::class,
            fn (PaymentCancelledNotification $n) => $n->transactionUuid === $transaction->uuid,
        );
        Notification::assertNotSentTo($transaction->user, PaymentSuccessfulNotification::class);
    }

    #[Test]
    public function the_buyer_is_emailed_when_they_cancel_an_unpaid_order(): void
    {
        Notification::fake();
        $transaction = $this->checkedOutTransaction('GR033');

        $this->postJson("/api/v1/public/transactions/{$transaction->uuid}/guest/cancel", ['email' => self::GUEST_EMAIL])
            ->assertJsonPath('message', 'Payment cancelled successfully');

        Notification::assertSentToTimes($transaction->user, PaymentCancelledNotification::class, 1);
    }

    #[Test]
    public function a_paid_order_gets_the_confirmation_and_never_the_cancellation(): void
    {
        Notification::fake();
        $transaction = $this->checkedOutTransaction('GR001');

        $this->postJson("/api/v1/public/transactions/{$transaction->uuid}/guest/cancel", ['email' => self::GUEST_EMAIL])
            ->assertJsonPath('message', 'Payment completed successfully');

        Notification::assertSentTo($transaction->user, PaymentSuccessfulNotification::class);
        Notification::assertNotSentTo($transaction->user, PaymentCancelledNotification::class);
    }

    #[Test]
    public function a_notification_reconciles_the_order_through_the_query_api(): void
    {
        $transaction = $this->checkedOutTransaction('GR001');

        // The posted response code is ignored; only the signed query decides.
        $this->postJson('/api/v1/payments/paynamics/notification', [
            'request_id' => $transaction->payment_order_id,
            'response_code' => 'GR003',
        ])
            ->assertOk()
            ->assertJsonPath('received', true)
            ->assertJsonPath('payment_status', Transaction::PAYMENT_STATUS['PAID'])
            ->assertJsonPath('message', 'Payment completed successfully');

        $this->assertSame(2, Ticket::where('transaction_uuid', $transaction->uuid)->count());
    }

    #[Test]
    public function a_notification_posted_as_plain_text_json_is_still_read(): void
    {
        $transaction = $this->checkedOutTransaction('GR001');

        $this->call(
            'POST',
            '/api/v1/payments/paynamics/notification',
            server: ['CONTENT_TYPE' => 'text/plain', 'HTTP_ACCEPT' => 'application/json'],
            content: json_encode(['request_id' => $transaction->payment_order_id, 'response_code' => 'GR001']),
        )
            ->assertOk()
            ->assertJsonPath('payment_status', Transaction::PAYMENT_STATUS['PAID']);
    }

    #[Test]
    public function a_notification_with_the_request_id_inside_the_transaction_is_read(): void
    {
        $transaction = $this->checkedOutTransaction('GR001');

        $this->postJson('/api/v1/payments/paynamics/notification', [
            'transaction' => ['request_id' => $transaction->payment_order_id],
        ])
            ->assertOk()
            ->assertJsonPath('payment_status', Transaction::PAYMENT_STATUS['PAID']);
    }

    #[Test]
    public function the_scheduled_check_confirms_paid_orders_without_a_signed_in_customer(): void
    {
        $transaction = $this->checkedOutTransaction('GR001');

        $this->artisan('app:auto-verify-pending-transaction')
            ->expectsOutputToContain("Verified transaction #{$transaction->uuid}.")
            ->assertSuccessful();

        $transaction->refresh();
        $this->assertSame(Transaction::PAYMENT_STATUS['PAID'], $transaction->payment_status);
        $this->assertSame(Transaction::ORDER_STATUS['CONFIRMED'], $transaction->order_status);
    }

    #[Test]
    public function the_scheduled_check_cancels_orders_paynamics_reports_as_cancelled(): void
    {
        $transaction = $this->checkedOutTransaction('GR053', 'Transaction cancelled');

        $this->artisan('app:auto-verify-pending-transaction')
            ->expectsOutputToContain("Transaction #{$transaction->uuid} was cancelled after gateway reported failure.")
            ->assertSuccessful();

        $this->assertSame(Transaction::ORDER_STATUS['CANCELLED'], $transaction->fresh()->order_status);
    }

    #[Test]
    public function a_notification_for_an_unknown_order_is_rejected(): void
    {
        $this->postJson('/api/v1/payments/paynamics/notification', ['request_id' => 'PAY-UNKNOWN'])
            ->assertNotFound()
            ->assertJsonPath('received', false);

        Http::assertNothingSent();
    }
}
