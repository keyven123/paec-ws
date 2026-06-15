<?php

namespace Tests\Unit;

use App\Services\Payments\PayMongoService;
use App\Services\Payments\PayPalService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaymentServiceUnitTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function paymongoServiceCreatesPaymentIntent()
    {
        // Mock PayMongo API response
        Http::fake([
            'api.paymongo.com/v1/payment_intents' => Http::response([
                'data' => [
                    'id' => 'pi_test_123',
                    'attributes' => [
                        'client_key' => 'pi_test_123_client_key',
                        'status' => 'awaiting_payment_method',
                        'amount' => 100000,
                        'currency' => 'PHP'
                    ]
                ]
            ], 200)
        ]);

        $service = new PayMongoService();
        $result = $service->createPaymentIntent([
            'amount' => 1000.00,
            'currency' => 'PHP',
            'description' => 'Test payment',
            'metadata' => ['test' => 'data']
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals('pi_test_123', $result['payment_id']);
        $this->assertEquals('pi_test_123_client_key', $result['client_key']);
        $this->assertEquals('awaiting_payment_method', $result['status']);
        $this->assertEquals(1000.00, $result['amount']);
        $this->assertEquals('PHP', $result['currency']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function paymongoServiceCapturesPayment()
    {
        // Mock PayMongo API response
        Http::fake([
            'api.paymongo.com/v1/payment_intents/pi_test_123/attach' => Http::response([
                'data' => [
                    'id' => 'pi_test_123',
                    'attributes' => [
                        'status' => 'succeeded',
                        'amount' => 100000,
                        'currency' => 'PHP'
                    ]
                ]
            ], 200)
        ]);

        $service = new PayMongoService();
        $result = $service->capturePayment('pi_test_123', [
            'payment_method_id' => 'pm_test_123',
            'return_url' => 'https://example.com/return'
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals('pi_test_123', $result['payment_id']);
        $this->assertEquals('succeeded', $result['status']);
        $this->assertEquals(1000.00, $result['amount']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function paymongoServiceRefundsPayment()
    {
        // Mock PayMongo API response
        Http::fake([
            'api.paymongo.com/v1/refunds' => Http::response([
                'data' => [
                    'id' => 'rf_test_123',
                    'attributes' => [
                        'status' => 'succeeded',
                        'amount' => 50000,
                        'currency' => 'PHP'
                    ]
                ]
            ], 200)
        ]);

        $service = new PayMongoService();
        $result = $service->refundPayment('pi_test_123', 500.00, 'Customer request');

        $this->assertTrue($result['success']);
        $this->assertEquals('rf_test_123', $result['refund_id']);
        $this->assertEquals('succeeded', $result['status']);
        $this->assertEquals(500.00, $result['amount']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function paymongoServiceGetsPaymentStatus()
    {
        // Mock PayMongo API response
        Http::fake([
            'api.paymongo.com/v1/payment_intents/pi_test_123' => Http::response([
                'data' => [
                    'id' => 'pi_test_123',
                    'attributes' => [
                        'status' => 'succeeded',
                        'amount' => 100000,
                        'currency' => 'PHP'
                    ]
                ]
            ], 200)
        ]);

        $service = new PayMongoService();
        $result = $service->getPaymentStatus('pi_test_123');

        $this->assertTrue($result['success']);
        $this->assertEquals('pi_test_123', $result['payment_id']);
        $this->assertEquals('succeeded', $result['status']);
        $this->assertEquals(1000.00, $result['amount']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function paymongoServiceVerifiesWebhook()
    {
        $service = new PayMongoService();
        $payload = '{"data":{"id":"evt_test_123"}}';
        $signature = hash_hmac('sha256', $payload, config('services.paymongo.webhook_secret'));

        $result = $service->verifyWebhook($payload, $signature);
        $this->assertTrue($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function paypalServiceCreatesOrder()
    {
        // Mock PayPal API responses
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
                'access_token' => 'test_access_token',
                'token_type' => 'Bearer',
                'expires_in' => 32400
            ], 200),
            'api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response([
                'id' => 'ORDER_TEST_123',
                'status' => 'CREATED',
                'links' => [
                    [
                        'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=ORDER_TEST_123',
                        'rel' => 'approve',
                        'method' => 'GET'
                    ]
                ]
            ], 200)
        ]);

        $service = new PayPalService();
        $result = $service->createPayment([
            'amount' => 1000.00,
            'currency' => 'PHP',
            'description' => 'Test payment',
            'return_url' => 'https://example.com/success',
            'cancel_url' => 'https://example.com/cancel'
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals('ORDER_TEST_123', $result['payment_id']);
        $this->assertEquals('CREATED', $result['status']);
        $this->assertStringContainsString('ORDER_TEST_123', $result['approval_url']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function paypalServiceCreatesCardOrder()
    {
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
                'access_token' => 'test_access_token',
                'token_type' => 'Bearer',
                'expires_in' => 32400
            ], 200),
            'api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response([
                'id' => 'CARD_ORDER_TEST_123',
                'status' => 'CREATED',
            ], 200)
        ]);

        $service = new PayPalService();
        $result = $service->createCardOrder([
            'amount' => 1250,
            'currency' => 'PHP',
            'description' => 'Card fields ticket purchase',
            'reference_id' => 'PAY-ORDER-123',
            'brand_name' => 'Sideline Test',
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals('CARD_ORDER_TEST_123', $result['payment_id']);
        $this->assertEquals('CREATED', $result['status']);
        $this->assertArrayNotHasKey('approval_url', $result);

        Http::assertSent(function ($request) {
            if (!str_contains($request->url(), '/v2/checkout/orders')) {
                return false;
            }

            $payload = $request->data();

            return $payload['intent'] === 'CAPTURE'
                && $payload['purchase_units'][0]['reference_id'] === 'PAY-ORDER-123'
                && $payload['purchase_units'][0]['amount']['currency_code'] === 'PHP'
                && $payload['purchase_units'][0]['amount']['value'] === '1250.00'
                && $payload['payment_source']['card']['experience_context']['shipping_preference'] === 'NO_SHIPPING'
                && $payload['payment_source']['card']['experience_context']['brand_name'] === 'Sideline Test'
                && !isset($payload['application_context']);
        });
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function paypalServiceCapturesOrder()
    {
        // Mock PayPal API responses
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
                'access_token' => 'test_access_token',
                'token_type' => 'Bearer',
                'expires_in' => 32400
            ], 200),
            'api-m.sandbox.paypal.com/v2/checkout/orders/ORDER_TEST_123/capture' => Http::response([
                'id' => 'ORDER_TEST_123',
                'status' => 'COMPLETED',
                'purchase_units' => [
                    [
                        'payments' => [
                            'captures' => [
                                [
                                    'id' => 'CAPTURE_TEST_123',
                                    'amount' => [
                                        'value' => '1000.00',
                                        'currency_code' => 'PHP'
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ], 200)
        ]);

        $service = new PayPalService();
        $result = $service->capturePayment('ORDER_TEST_123');

        $this->assertTrue($result['success']);
        $this->assertEquals('ORDER_TEST_123', $result['payment_id']);
        $this->assertEquals('CAPTURE_TEST_123', $result['capture_id']);
        $this->assertEquals('COMPLETED', $result['status']);
        $this->assertEquals('1000.00', $result['amount']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function paypalServiceRefundsPayment()
    {
        // Mock PayPal API responses
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
                'access_token' => 'test_access_token',
                'token_type' => 'Bearer',
                'expires_in' => 32400
            ], 200),
            'api-m.sandbox.paypal.com/v2/payments/captures/CAPTURE_TEST_123/refund' => Http::response([
                'id' => 'REFUND_TEST_123',
                'status' => 'COMPLETED',
                'amount' => [
                    'value' => '500.00',
                    'currency_code' => 'PHP'
                ]
            ], 200)
        ]);

        $service = new PayPalService();
        $result = $service->refundPayment('CAPTURE_TEST_123', 500.00, 'Customer request');

        $this->assertTrue($result['success']);
        $this->assertEquals('REFUND_TEST_123', $result['refund_id']);
        $this->assertEquals('COMPLETED', $result['status']);
        $this->assertEquals('500.00', $result['amount']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function paypalServiceGetsPaymentStatus()
    {
        // Mock PayPal API responses
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
                'access_token' => 'test_access_token',
                'token_type' => 'Bearer',
                'expires_in' => 32400
            ], 200),
            'api-m.sandbox.paypal.com/v2/checkout/orders/ORDER_TEST_123' => Http::response([
                'id' => 'ORDER_TEST_123',
                'status' => 'COMPLETED',
                'purchase_units' => [
                    [
                        'payments' => [
                            'captures' => [
                                [
                                    'amount' => [
                                        'value' => '1000.00',
                                        'currency_code' => 'PHP'
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ], 200)
        ]);

        $service = new PayPalService();
        $result = $service->getPaymentStatus('ORDER_TEST_123');

        $this->assertTrue($result['success']);
        $this->assertEquals('ORDER_TEST_123', $result['payment_id']);
        $this->assertEquals('COMPLETED', $result['status']);
        $this->assertEquals('1000.00', $result['amount']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function paymongoServiceHandlesApiErrors()
    {
        // Mock PayMongo API error response
        Http::fake([
            'api.paymongo.com/v1/payment_intents' => Http::response([
                'errors' => [
                    ['detail' => 'Invalid amount']
                ]
            ], 400)
        ]);

        $service = new PayMongoService();
        $result = $service->createPayment([
            'amount' => -100.00, // Invalid amount
            'currency' => 'PHP'
        ]);

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function paypalServiceHandlesApiErrors()
    {
        // Mock PayPal API error responses
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
                'error' => 'invalid_client',
                'error_description' => 'Client authentication failed'
            ], 401)
        ]);

        $service = new PayPalService();
        $result = $service->createPayment([
            'amount' => 1000.00,
            'currency' => 'PHP'
        ]);

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
    }
}
