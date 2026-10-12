<?php

namespace Tests\Unit\Coverage\RPayments;

use App\Services\Payments\PayMongoService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PayMongoServiceCoverageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    private function service(): PayMongoService
    {
        return new PayMongoService('sk_test_secret', 'pk_test_public', 'whsec_test');
    }

    private function checkoutSession(array $attributes): array
    {
        return [
            'data' => [
                'id' => 'cs_test_123',
                'attributes' => array_merge([
                    'status' => 'active',
                    'line_items' => [['amount' => 150000, 'currency' => 'PHP']],
                ], $attributes),
            ],
        ];
    }

    #[Test]
    public function createPaymentFailsWithoutCallingTheApiWhenSecretKeyIsMissing(): void
    {
        Http::fake();
        config(['services.paymongo.secret_key' => null]);

        $result = (new PayMongoService())->createPayment(['amount' => 100]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('PayMongo secret key is missing', $result['error']);
        Http::assertNothingSent();
    }

    #[Test]
    public function createPaymentIntentReturnsErrorWhenApiRejectsRequest(): void
    {
        Log::spy();
        Http::fake([
            'api.paymongo.com/v1/payment_intents' => Http::response(['errors' => [['detail' => 'amount is invalid']]], 400),
        ]);

        $result = $this->service()->createPaymentIntent(['amount' => 0]);

        $this->assertFalse($result['success']);
        $this->assertStringStartsWith('PayMongo API Error: ', $result['error']);
        $this->assertStringContainsString('amount is invalid', $result['error']);
        Log::shouldHaveReceived('error')->withArgs(
            fn (string $message) => str_starts_with($message, 'PayMongo createPaymentIntent error:')
        )->once();
    }

    #[Test]
    public function capturePaymentReturnsErrorOnServerError(): void
    {
        Http::fake([
            'api.paymongo.com/v1/payment_intents/pi_123/attach' => Http::response(['errors' => [['detail' => 'boom']]], 500),
        ]);

        $result = $this->service()->capturePayment('pi_123', ['payment_method_id' => 'pm_1']);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('PayMongo API Error:', $result['error']);
        Http::assertSent(fn (Request $request) => $request['data']['attributes']['payment_method'] === 'pm_1');
    }

    #[Test]
    public function capturePaymentReturnsErrorOnConnectionFailure(): void
    {
        Http::fake(['api.paymongo.com/*' => Http::failedConnection('Connection timed out')]);

        $result = $this->service()->capturePayment('pi_123', ['payment_method_id' => 'pm_1']);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Connection timed out', $result['error']);
    }

    #[Test]
    public function cancelPaymentSkipsIdsThatAreNotCheckoutSessions(): void
    {
        Http::fake();

        $result = $this->service()->cancelPayment('pi_123');

        $this->assertSame([
            'success' => false,
            'skipped' => true,
            'error' => 'Unsupported PayMongo payment id for cancellation: pi_123',
        ], $result);
        Http::assertNothingSent();
    }

    #[Test]
    public function cancelPaymentTreatsAlreadyExpiredSessionAsSuccess(): void
    {
        $body = ['errors' => [['code' => 'resource_state_error', 'detail' => 'The checkout session has already Expired.']]];
        Http::fake(['api.paymongo.com/v1/checkout_sessions/cs_abc/expire' => Http::response($body, 400)]);

        $result = $this->service()->cancelPayment('cs_abc');

        $this->assertSame(['success' => true, 'already_expired' => true, 'raw_response' => $body], $result);
    }

    #[Test]
    public function cancelPaymentReturnsErrorForUnexpectedFailure(): void
    {
        Http::fake(['api.paymongo.com/v1/checkout_sessions/cs_abc/expire' => Http::response('Bad Gateway', 502)]);

        $result = $this->service()->cancelPayment('cs_abc');

        $this->assertFalse($result['success']);
        $this->assertArrayNotHasKey('already_paid', $result);
        $this->assertSame('PayMongo expire checkout session error: Bad Gateway', $result['error']);
    }

    #[Test]
    public function refundPaymentReturnsErrorWhenApiRejectsRefund(): void
    {
        Http::fake([
            'api.paymongo.com/v1/refunds' => Http::response(['errors' => [['detail' => 'refund exceeds amount']]], 422),
        ]);

        $result = $this->service()->refundPayment('pay_123', 50.5, '');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('refund exceeds amount', $result['error']);
        Http::assertSent(fn (Request $request) => $request['data']['attributes']['amount'] === 5050
            && $request['data']['attributes']['reason'] === 'requested_by_customer');
    }

    #[Test]
    public function getPaymentStatusReturnsErrorWhenPaymentIntentNotFound(): void
    {
        Http::fake([
            'api.paymongo.com/v1/payment_intents/pi_missing' => Http::response(['errors' => [['detail' => 'not found']]], 404),
        ]);

        $result = $this->service()->getPaymentStatus('pi_missing');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not found', $result['error']);
    }

    #[Test]
    public function getCheckoutSessionStatusReturnsErrorWhenApiFails(): void
    {
        Http::fake(['api.paymongo.com/v1/checkout_sessions/cs_test_123' => Http::response('oops', 500)]);

        $result = $this->service()->getCheckoutSessionStatus('cs_test_123');

        $this->assertSame(['success' => false, 'error' => 'PayMongo API Error: oops'], $result);
    }

    #[Test]
    public function getCheckoutSessionStatusMapsFailedPaymentIntentToFailed(): void
    {
        Http::fake(['api.paymongo.com/v1/checkout_sessions/cs_test_123' => Http::response($this->checkoutSession([
            'payment_intent' => ['id' => 'pi_9', 'attributes' => ['status' => 'cancelled']],
        ]))]);

        $result = $this->service()->getCheckoutSessionStatus('cs_test_123');

        $this->assertTrue($result['success']);
        $this->assertSame('failed', $result['status']);
        $this->assertSame('pi_9', $result['payment_intent_id']);
        $this->assertEquals(1500, $result['amount']);
    }

    #[Test]
    public function getCheckoutSessionStatusUsesPaidPaymentFromPaymentsArray(): void
    {
        Http::fake(['api.paymongo.com/v1/checkout_sessions/cs_test_123' => Http::response($this->checkoutSession([
            'payment_intent' => ['id' => 'pi_9', 'attributes' => ['status' => 'awaiting_payment_method']],
            'payments' => [
                ['attributes' => ['status' => 'failed']],
                ['attributes' => ['status' => 'paid']],
            ],
        ]))]);

        $result = $this->service()->getCheckoutSessionStatus('cs_test_123');

        $this->assertSame('succeeded', $result['status']);
    }

    #[Test]
    public function getCheckoutSessionStatusFallsBackToPaidSessionStatus(): void
    {
        Http::fake(['api.paymongo.com/v1/checkout_sessions/cs_test_123' => Http::response($this->checkoutSession([
            'status' => 'paid',
        ]))]);

        $result = $this->service()->getCheckoutSessionStatus('cs_test_123');

        $this->assertSame('succeeded', $result['status']);
        $this->assertNull($result['payment_intent_id']);
    }

    #[Test]
    public function getCheckoutSessionStatusFallsBackToExpiredSessionStatus(): void
    {
        Http::fake(['api.paymongo.com/v1/checkout_sessions/cs_test_123' => Http::response($this->checkoutSession([
            'status' => 'expired',
            'payments' => [],
        ]))]);

        $result = $this->service()->getCheckoutSessionStatus('cs_test_123');

        $this->assertSame('failed', $result['status']);
    }

    #[Test]
    public function getCheckoutSessionStatusReturnsErrorForMalformedBody(): void
    {
        Http::fake(['api.paymongo.com/v1/checkout_sessions/cs_test_123' => Http::response(['data' => ['id' => 'cs_test_123']])]);

        $result = $this->service()->getCheckoutSessionStatus('cs_test_123');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('attributes', $result['error']);
    }

    #[Test]
    public function createPaymentMethodFailsWhenPublicKeyIsMissing(): void
    {
        Http::fake();
        config(['services.paymongo.public_key' => '  ']);

        $result = (new PayMongoService('sk_test_secret'))->createPaymentMethod(['type' => 'card']);

        $this->assertSame([
            'success' => false,
            'error' => 'PayMongo public key is missing. Set PAYMONGO_PUBLIC_KEY in .env.',
        ], $result);
        Http::assertNothingSent();
    }

    #[Test]
    public function createPaymentMethodUsesPublicKeyAndReturnsMethod(): void
    {
        Http::fake(['api.paymongo.com/v1/payment_methods' => Http::response([
            'data' => ['id' => 'pm_123', 'attributes' => ['type' => 'gcash']],
        ])]);

        $result = $this->service()->createPaymentMethod(['type' => 'gcash']);

        $this->assertTrue($result['success']);
        $this->assertSame('pm_123', $result['payment_method_id']);
        $this->assertSame('gcash', $result['type']);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.paymongo.com/v1/payment_methods'
            && $request->hasHeader('Authorization', 'Basic ' . base64_encode('pk_test_public:'))
            && $request['data']['attributes'] === ['type' => 'gcash']);
    }

    #[Test]
    public function createPaymentMethodReturnsErrorWhenApiRejectsRequest(): void
    {
        Log::spy();
        Http::fake(['api.paymongo.com/v1/payment_methods' => Http::response(['errors' => [['detail' => 'invalid type']]], 400)]);

        $result = $this->service()->createPaymentMethod(['type' => 'bogus']);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('invalid type', $result['error']);
        Log::shouldHaveReceived('error')->withArgs(
            fn (string $message) => str_starts_with($message, 'PayMongo createPaymentMethod error:')
        )->once();
    }
}
