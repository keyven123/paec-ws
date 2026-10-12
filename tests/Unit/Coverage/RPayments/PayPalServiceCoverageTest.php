<?php

namespace Tests\Unit\Coverage\RPayments;

use App\Services\Payments\PayPalService;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PayPalServiceCoverageTest extends TestCase
{
    private const SANDBOX = 'https://api-m.sandbox.paypal.com';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    private function service(): PayPalService
    {
        return new PayPalService('client-id-123456', 'client-secret', 'sandbox');
    }

    private function tokenResponse(): \GuzzleHttp\Promise\PromiseInterface
    {
        return Http::response(['access_token' => 'A21-token', 'token_type' => 'Bearer']);
    }

    #[Test]
    public function missingCredentialsAreLoggedAndRejectedBeforeAnyRequest(): void
    {
        Log::spy();
        Http::fake();

        $result = (new PayPalService('', '', 'sandbox'))->createPayment(['amount' => 10]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Missing: PAYPAL_CLIENT_ID, PAYPAL_CLIENT_SECRET.', $result['error']);
        Log::shouldHaveReceived('warning')->with('PayPal credentials not configured', [
            'client_id_set' => false,
            'client_secret_set' => false,
            'mode' => 'sandbox',
        ])->once();
        Log::shouldHaveReceived('error')->withArgs(
            fn (string $message, array $context = []) => str_starts_with($message, 'PayPal credentials are not configured')
                && $context['client_id_value'] === 'NOT SET'
                && $context['base_url'] === self::SANDBOX
        )->once();
        Http::assertNothingSent();
    }

    #[Test]
    public function missingSecretOnlyReportsTheSecret(): void
    {
        Http::fake();

        $result = (new PayPalService('client-id-123456', '', 'sandbox'))->getPaymentStatus('ORDER-1');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Missing: PAYPAL_CLIENT_SECRET.', $result['error']);
        $this->assertStringNotContainsString('PAYPAL_CLIENT_ID,', $result['error']);
    }

    #[Test]
    public function liveModeUsesProductionHost(): void
    {
        Http::fake([
            'api-m.paypal.com/v1/oauth2/token' => $this->tokenResponse(),
            'api-m.paypal.com/v2/checkout/orders/ORDER-1' => Http::response(['id' => 'ORDER-1', 'status' => 'APPROVED']),
        ]);

        $result = (new PayPalService('client-id-123456', 'client-secret', 'live'))->getPaymentStatus('ORDER-1');

        $this->assertTrue($result['success']);
        $this->assertSame('APPROVED', $result['status']);
        $this->assertNull($result['amount']);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api-m.paypal.com/v2/checkout/orders/ORDER-1'
            && $request->hasHeader('Authorization', 'Bearer A21-token'));
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'sandbox'));
    }

    #[Test]
    public function authFailureWithOtherErrorUsesErrorDescription(): void
    {
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
                'error' => 'unauthorized_client',
                'error_description' => 'Client is not authorized for this grant',
            ], 401),
        ]);

        $result = $this->service()->createPayment(['amount' => 10]);

        $this->assertFalse($result['success']);
        $this->assertSame('PayPal authentication failed. Client is not authorized for this grant', $result['error']);
    }

    #[Test]
    public function authFailureWithoutErrorKeyUsesRawBody(): void
    {
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response('Service Unavailable', 503),
        ]);

        $result = $this->service()->createPayment(['amount' => 10]);

        $this->assertSame('PayPal authentication failed. Service Unavailable', $result['error']);
    }

    #[Test]
    public function successfulAuthWithoutAccessTokenIsRejected(): void
    {
        Log::spy();
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['token_type' => 'Bearer']),
        ]);

        $result = $this->service()->createPayment(['amount' => 10]);

        $this->assertSame('PayPal authentication failed: Access token not received', $result['error']);
        Log::shouldHaveReceived('error')->with('PayPal access token not found in response', ['response' => ['token_type' => 'Bearer']])->once();
        Log::shouldNotHaveReceived('error', ['PayPal getAccessToken error: PayPal authentication failed: Access token not received']);
    }

    #[Test]
    public function connectionFailureDuringAuthIsLoggedAndReturned(): void
    {
        Log::spy();
        Http::fake(['api-m.sandbox.paypal.com/*' => Http::failedConnection('cURL error 28: timed out')]);

        $result = $this->service()->createPayment(['amount' => 10]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('timed out', $result['error']);
        Log::shouldHaveReceived('error')->withArgs(
            fn (string $message) => str_starts_with($message, 'PayPal getAccessToken error:') && str_contains($message, 'timed out')
        )->once();
    }

    #[Test]
    public function createPaymentReturnsErrorWhenOrderCreationFails(): void
    {
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => $this->tokenResponse(),
            'api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response(['name' => 'INVALID_REQUEST'], 400),
        ]);

        $result = $this->service()->createPayment(['amount' => 10]);

        $this->assertFalse($result['success']);
        $this->assertSame('PayPal API Error: {"name":"INVALID_REQUEST"}', $result['error']);
    }

    #[Test]
    public function refundPaymentReturnsErrorWhenApiRejectsRefund(): void
    {
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => $this->tokenResponse(),
            'api-m.sandbox.paypal.com/v2/payments/captures/CAP-1/refund' => Http::response(['name' => 'CAPTURE_FULLY_REFUNDED'], 422),
        ]);

        $result = $this->service()->refundPayment('CAP-1', 25.0);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('CAPTURE_FULLY_REFUNDED', $result['error']);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/refund')
            && $request['amount']['value'] === '25.00'
            && $request['note_to_payer'] === 'Refund for ticket purchase');
    }

    private function bindWebhookRequest(): void
    {
        $this->app->instance('request', HttpRequest::create('/webhooks/paypal', 'POST', server: [
            'HTTP_PAYPAL_AUTH_ALGO' => 'SHA256withRSA',
            'HTTP_PAYPAL_CERT_ID' => 'cert-1',
            'HTTP_PAYPAL_TRANSMISSION_ID' => 'tx-1',
            'HTTP_PAYPAL_TRANSMISSION_TIME' => '2026-10-12T00:00:00Z',
        ]));
    }

    #[Test]
    public function verifyWebhookSendsTransmissionHeadersAndAcceptsSuccess(): void
    {
        config(['services.paypal.webhook_id' => 'WH-123']);
        $this->bindWebhookRequest();
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => $this->tokenResponse(),
            'api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS']),
        ]);

        $this->assertTrue($this->service()->verifyWebhook('{"id":"WH-EVT-1"}', 'sig-abc'));

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/verify-webhook-signature')
            && $request['auth_algo'] === 'SHA256withRSA'
            && $request['cert_id'] === 'cert-1'
            && $request['transmission_id'] === 'tx-1'
            && $request['transmission_time'] === '2026-10-12T00:00:00Z'
            && $request['transmission_sig'] === 'sig-abc'
            && $request['webhook_id'] === 'WH-123'
            && $request['webhook_event'] === ['id' => 'WH-EVT-1']);
    }

    #[Test]
    public function verifyWebhookRejectsFailedVerificationStatus(): void
    {
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => $this->tokenResponse(),
            'api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'FAILURE']),
        ]);

        $this->assertFalse($this->service()->verifyWebhook('{}', 'sig'));
    }

    #[Test]
    public function verifyWebhookRejectsWhenVerificationEndpointFails(): void
    {
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => $this->tokenResponse(),
            'api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature' => Http::response(['name' => 'INTERNAL'], 500),
        ]);

        $this->assertFalse($this->service()->verifyWebhook('{}', 'sig'));
    }

    #[Test]
    public function verifyWebhookReturnsFalseAndLogsWhenAuthFails(): void
    {
        Log::spy();
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['error' => 'invalid_client'], 401),
        ]);

        $this->assertFalse($this->service()->verifyWebhook('{}', 'sig'));

        Log::shouldHaveReceived('error')->withArgs(
            fn (string $message) => str_starts_with($message, 'PayPal webhook verification error: PayPal authentication failed. Invalid client credentials.')
        )->once();
    }

    #[Test]
    public function captureFailureWithNonJsonBodyReturnsGenericMessage(): void
    {
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => $this->tokenResponse(),
            'api-m.sandbox.paypal.com/v2/checkout/orders/ORDER-1/capture' => Http::response('<html>Bad Gateway</html>', 502),
        ]);

        $result = $this->service()->capturePayment('ORDER-1');

        $this->assertSame([
            'success' => false,
            'error' => 'Payment processing failed. Please try again or use a different payment method.',
        ], $result);
    }

    #[Test]
    public function captureFailureWithUnknownIssueFallsBackToPayPalMessage(): void
    {
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => $this->tokenResponse(),
            'api-m.sandbox.paypal.com/v2/checkout/orders/ORDER-1/capture' => Http::response([
                'name' => 'UNPROCESSABLE_ENTITY',
                'message' => 'The requested action could not be performed.',
                'details' => [['issue' => 'ORDER_NOT_APPROVED']],
            ], 422),
        ]);

        $result = $this->service()->capturePayment('ORDER-1');

        $this->assertSame('The requested action could not be performed.', $result['error']);
    }

    #[Test]
    public function captureFailureWithoutMessageReturnsGenericMessage(): void
    {
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => $this->tokenResponse(),
            'api-m.sandbox.paypal.com/v2/checkout/orders/ORDER-1/capture' => Http::response(['name' => 'INTERNAL_SERVER_ERROR'], 500),
        ]);

        $result = $this->service()->capturePayment('ORDER-1');

        $this->assertSame('Payment processing failed. Please try again or use a different payment method.', $result['error']);
    }
}
