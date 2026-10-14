<?php

namespace Tests\Unit\Payments;

use App\Models\PaymentGateway;
use App\Services\Payments\PaymentServiceFactory;
use App\Services\Payments\PaynamicsService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PaynamicsServiceTest extends TestCase
{
    private const HOST = 'https://payin.payserv.net';
    private const REFUND_URL = self::HOST . '/paygate/transactions/refund';
    private const QUERY_URL = self::HOST . '/paygate/transactions/query';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    private function service(): PaynamicsService
    {
        return new PaynamicsService('MERCHANT000123', 'mkey-secret-value', 'user', 'pass', self::HOST);
    }

    #[Test]
    public function refunds_are_signed_with_the_documented_field_order(): void
    {
        Http::fake([self::REFUND_URL => Http::response(['response_code' => 'GR001', 'response_id' => 'REFUND-1'])]);

        $result = $this->service()->refundPayment('RESP-1', 250.5, 'Event cancelled');

        $this->assertTrue($result['success']);
        $this->assertSame('REFUND-1', $result['refund_id']);
        $this->assertSame('completed', $result['status']);
        $this->assertNull($result['error']);

        Http::assertSent(fn (Request $request) => $request['org_trxid'] === 'RESP-1'
            && $request['amount'] === '250.50'
            && $request['merchantid'] === 'MERCHANT000123'
            && $request['signature'] === hash('sha512', 'MERCHANT000123' . $request['request_id'] . 'RESP-1'
                . $request['ip_address'] . '' . '' . '250.50' . 'Event cancelled' . 'mkey-secret-value'));
    }

    #[Test]
    public function refund_results_report_pending_and_declined_refunds(): void
    {
        Http::fake([self::REFUND_URL => Http::sequence()
            ->push(['response_code' => 'GR033'])
            ->push(['response_code' => 'GR003', 'response_message' => 'Refund not allowed'])
            ->push([], 500)]);

        $pending = $this->service()->refundPayment('RESP-1', 100);
        $this->assertTrue($pending['success']);
        $this->assertSame('pending', $pending['status']);

        $declined = $this->service()->refundPayment('RESP-1', 100);
        $this->assertFalse($declined['success']);
        $this->assertSame('failed', $declined['status']);
        $this->assertSame('Refund not allowed', $declined['error']);

        $serverError = $this->service()->refundPayment('RESP-1', 100);
        $this->assertSame('Paynamics did not accept the refund.', $serverError['error']);
    }

    #[Test]
    public function unreachable_refunds_fail_cleanly(): void
    {
        Http::fake([self::REFUND_URL => fn () => throw new ConnectionException('timed out')]);

        $this->assertSame(
            ['success' => false, 'error' => 'Paynamics could not be reached. Please try again.'],
            $this->service()->refundPayment('RESP-1', 100),
        );
    }

    #[Test]
    public function capturing_reports_the_current_status(): void
    {
        Http::fake([self::QUERY_URL => fn (Request $request) => Http::response([
            'transaction' => ['request_id' => $request['org_trxid2'], 'response_code' => 'GR002'],
        ])]);

        $result = $this->service()->capturePayment('PAY-1');

        $this->assertTrue($result['success']);
        $this->assertSame('paid', $result['status']);
    }

    #[Test]
    public function an_unknown_channel_is_refused_before_calling_paynamics(): void
    {
        $result = $this->service()->createPayment(['payment_channel' => 'cliqq', 'reference_id' => 'PAY-1']);

        $this->assertFalse($result['success']);
        Http::assertNothingSent();
    }

    #[Test]
    public function a_gateway_paybiz_wallet_receives_the_full_sale_as_a_signed_settlement(): void
    {
        Http::fake([self::HOST . '/paygate/transactions/' => Http::response([
            'response_code' => 'GR001',
            'payment_action_info' => ['url' => 'https://payin.payserv.net/pay/abc'],
        ])]);
        $gateway = new PaymentGateway([
            'provider' => 'paynamics',
            'environment' => 'testing',
            'public_key' => 'MERCHANT000123',
            'secret_key' => 'mkey-secret-value',
            'api_username' => 'user',
            'api_password' => 'pass',
            'paybiz_wallet_id' => 'PBW-0001',
        ]);

        $result = PaymentServiceFactory::forGateway($gateway)->createPayment($this->sale());

        $this->assertTrue($result['success']);
        Http::assertSent(fn (Request $request) => $request['settlement_information'] === [[
            'biz_wallet_id' => 'PBW-0001',
            'settlement_amount' => '1250.00',
            'settlement_currency' => 'PHP',
            'reason' => 'Sale PAY-20261014-ABCD1234',
            'settlement_id' => 'STL-PAY-20261014-ABCD1234',
            'signature' => hash('sha512', 'PBW-0001' . '1250.00' . 'PHP' . 'Sale PAY-20261014-ABCD1234'
                . 'STL-PAY-20261014-ABCD1234' . 'mkey-secret-value'),
        ]]);
    }

    #[Test]
    public function without_a_paybiz_wallet_the_sale_settles_to_the_merchant_account(): void
    {
        Http::fake([self::HOST . '/paygate/transactions/' => Http::response([
            'response_code' => 'GR001',
            'payment_action_info' => ['url' => 'https://payin.payserv.net/pay/abc'],
        ])]);

        $this->assertTrue($this->service()->createPayment($this->sale())['success']);
        Http::assertSent(fn (Request $request) => ! isset($request['settlement_information']));
    }

    /**
     * @return array<string, mixed>
     */
    private function sale(): array
    {
        return [
            'reference_id' => 'PAY-20261014-ABCD1234',
            'amount' => 1250,
            'currency' => 'PHP',
            'description' => 'Tickets',
            'return_url' => 'https://example.test/return',
            'cancel_url' => 'https://example.test/cancel',
            'notification_url' => 'https://example.test/notify',
            'payment_channel' => 'gcash',
        ];
    }

    #[Test]
    public function posted_notifications_are_never_trusted_on_their_own(): void
    {
        $this->assertFalse($this->service()->verifyWebhook('{"request_id":"PAY-1"}', 'signature'));
    }
}
