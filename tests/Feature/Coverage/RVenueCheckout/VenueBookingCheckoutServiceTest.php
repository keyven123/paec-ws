<?php

namespace Tests\Feature\Coverage\RVenueCheckout;

use App\Models\Transaction;
use App\Models\User;
use App\Models\VenueInquiry;
use App\Models\VenueListing;
use App\Services\Checkout\VenueBookingCheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VenueBookingCheckoutServiceTest extends TestCase
{
    use CreatesVenueCustomers;
    use RefreshDatabase;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        config([
            'app.frontend_url' => 'https://front.test',
            'app.name' => 'Ticketoc Test',
            'services.paymongo.secret_key' => 'sk_test_venue',
            'services.paymongo.public_key' => 'pk_test_venue',
            'services.paypal.client_id' => 'paypal-client',
            'services.paypal.client_secret' => 'paypal-secret',
            'services.paypal.mode' => 'sandbox',
        ]);

        $this->customer = $this->createVenueCustomer();
    }

    private function service(): VenueBookingCheckoutService
    {
        return app(VenueBookingCheckoutService::class);
    }

    private function inquiry(array $overrides = []): VenueInquiry
    {
        return VenueInquiry::factory()->create(array_merge([
            'user_uuid' => $this->customer->uuid,
            'email' => $this->customer->email,
        ], $overrides));
    }

    private function assertValidationError(callable $callback, string $field, string $message): void
    {
        try {
            $callback();
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
            $this->assertStringContainsString($message, $e->errors()[$field][0]);
        }
    }

    #[Test]
    public function balancePaymentIsRejectedWhenInquiryIsNotInBalanceDue(): void
    {
        $inquiry = $this->inquiry(['status' => VenueInquiry::STATUSES['DEPOSIT_PAID']]);

        $this->assertValidationError(
            fn () => $this->service()->startPayment($inquiry, $this->customer->uuid, [
                'payment_phase' => VenueInquiry::PAYMENT_PHASE_BALANCE,
                'payment_provider' => 'paymongo',
            ]),
            'inquiry',
            'not ready for final balance payment',
        );

        $this->assertSame(0, Transaction::count());
        Http::assertNothingSent();
    }

    #[Test]
    public function balancePaymentWithZeroAmountIsRejected(): void
    {
        $inquiry = $this->inquiry([
            'status' => VenueInquiry::STATUSES['BALANCE_DUE'],
            'balance_amount' => 0,
            'additional_charges' => 0,
        ]);

        $this->assertValidationError(
            fn () => $this->service()->startPayment($inquiry, $this->customer->uuid, [
                'payment_phase' => VenueInquiry::PAYMENT_PHASE_BALANCE,
                'payment_provider' => 'paymongo',
            ]),
            'inquiry',
            'no payable amount',
        );

        $this->assertSame(0, Transaction::count());
    }

    #[Test]
    public function depositPaymentThroughPaymongoCreatesTransactionAndCheckoutSession(): void
    {
        Http::fake([
            'https://api.paymongo.com/v1/checkout_sessions' => Http::response([
                'data' => [
                    'id' => 'cs_venue_deposit',
                    'attributes' => [
                        'checkout_url' => 'https://checkout.paymongo.test/cs_venue_deposit',
                        'status' => 'active',
                        'line_items' => [['amount' => 1500000, 'currency' => 'PHP']],
                    ],
                ],
            ]),
        ]);

        $inquiry = $this->inquiry([
            'status' => VenueInquiry::STATUSES['DEPOSIT_REQUESTED'],
            'deposit_amount' => 15000,
        ]);
        $listing = VenueListing::query()->findOrFail($inquiry->venue_listing_uuid);

        $result = $this->service()->startPayment($inquiry, $this->customer->uuid, [
            'payment_provider' => 'paymongo',
            'payment_methods' => ['gcash', 'card'],
        ]);

        $this->assertSame(VenueInquiry::PAYMENT_PHASE_DEPOSIT, $result['payment_phase']);
        $this->assertTrue($result['payment_result']['success']);

        /** @var Transaction $transaction */
        $transaction = $result['transaction'];
        $this->assertSame('cs_venue_deposit', $transaction->payment_id);
        $this->assertSame('active', $transaction->payment_status);
        $this->assertSame('venue_inquiry', $transaction->transactionable_type);
        $this->assertSame($inquiry->uuid, $transaction->transactionable_uuid);
        $this->assertSame($listing->organization_uuid, $transaction->organization_uuid);
        $this->assertStringStartsWith('VEN-DEP-', $transaction->order_number);
        $this->assertEquals(15000, (float) $transaction->total_amount);
        $this->assertSame(VenueInquiry::PAYMENT_PHASE_DEPOSIT, $transaction->payment_data['venue_payment_phase']);
        $this->assertSame('cs_venue_deposit', $transaction->payment_data['raw_response']['data']['id']);

        Http::assertSent(function (Request $request) use ($transaction, $listing) {
            $attributes = $request['data']['attributes'];

            return $request->url() === 'https://api.paymongo.com/v1/checkout_sessions'
                && $attributes['statement_descriptor'] === 'VENUE'
                && $attributes['payment_method_types'] === ['gcash', 'card']
                && $attributes['description'] === 'Venue booking deposit for ' . $listing->name
                && $attributes['success_url'] === 'https://front.test/payment/success/' . $transaction->uuid
                && $attributes['cancel_url'] === 'https://front.test/payment/cancel/' . $transaction->uuid
                && $attributes['metadata']['venue_payment_phase'] === VenueInquiry::PAYMENT_PHASE_DEPOSIT;
        });
    }

    #[Test]
    public function balancePaymentThroughPaypalSendsPaypalOptionsAndCustomUrls(): void
    {
        Http::fake([
            'https://api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'paypal-token']),
            'https://api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response([
                'id' => 'PAYPAL-ORDER-1',
                'status' => 'CREATED',
                'links' => [['rel' => 'approve', 'href' => 'https://paypal.test/approve']],
            ]),
        ]);

        $inquiry = $this->inquiry([
            'status' => VenueInquiry::STATUSES['BALANCE_DUE'],
            'balance_amount' => 40000,
            'additional_charges' => 2500,
        ]);

        $result = $this->service()->startPayment($inquiry, $this->customer->uuid, [
            'payment_phase' => VenueInquiry::PAYMENT_PHASE_BALANCE,
            'payment_provider' => 'paypal',
            'return_url' => 'https://front.test/custom/return',
            'cancel_url' => 'https://front.test/custom/cancel',
        ]);

        $this->assertSame(VenueInquiry::PAYMENT_PHASE_BALANCE, $result['payment_phase']);
        $this->assertSame('https://paypal.test/approve', $result['payment_result']['approval_url']);

        $transaction = $result['transaction'];
        $this->assertStringStartsWith('VEN-BAL-', $transaction->order_number);
        $this->assertEquals(42500, (float) $transaction->total_amount);
        $this->assertSame('PAYPAL-ORDER-1', $transaction->payment_id);
        $this->assertSame('CREATED', $transaction->payment_status);

        Http::assertSent(function (Request $request) {
            if ($request->url() !== 'https://api-m.sandbox.paypal.com/v2/checkout/orders') {
                return false;
            }
            $context = $request['application_context'];

            return $context['brand_name'] === 'Ticketoc Test'
                && $context['landing_page'] === 'LOGIN'
                && $context['shipping_preference'] === 'NO_SHIPPING'
                && $context['user_action'] === 'PAY_NOW'
                && $context['return_url'] === 'https://front.test/custom/return'
                && $context['cancel_url'] === 'https://front.test/custom/cancel'
                && $request['purchase_units'][0]['amount']['value'] === '42500.00';
        });
    }

    #[Test]
    public function failedProviderResponseRaisesPaymentValidationErrorAndLeavesTransactionUnpaid(): void
    {
        Http::fake([
            'https://api.paymongo.com/v1/checkout_sessions' => Http::response(['errors' => [['detail' => 'boom']]], 500),
        ]);

        $inquiry = $this->inquiry([
            'status' => VenueInquiry::STATUSES['DEPOSIT_REQUESTED'],
            'deposit_amount' => 5000,
        ]);
        VenueListing::query()->whereKey($inquiry->venue_listing_uuid)->first()->delete();

        $this->assertValidationError(
            fn () => $this->service()->startPayment($inquiry->fresh(), $this->customer->uuid, [
                'payment_provider' => 'paymongo',
            ]),
            'payment',
            'Payment creation failed: PayMongo API Error',
        );

        $transaction = Transaction::query()->sole();
        $this->assertNull($transaction->payment_id);
        $this->assertNull($transaction->organization_uuid);

        Http::assertSent(function (Request $request) {
            $attributes = $request['data']['attributes'];

            return $attributes['description'] === 'Venue booking deposit for venue'
                && in_array('gcash', $attributes['payment_method_types'], true)
                && $attributes['statement_descriptor'] === 'VENUE';
        });
    }
}
