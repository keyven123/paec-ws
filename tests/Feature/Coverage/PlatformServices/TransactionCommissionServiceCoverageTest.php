<?php

namespace Tests\Feature\Coverage\PlatformServices;

use App\Models\Dataset;
use App\Models\Transaction;
use App\Models\TransactionCommission;
use App\Services\Platform\TransactionCommissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TransactionCommissionServiceCoverageTest extends TestCase
{
    use PlatformFixtures;
    use RefreshDatabase;

    private TransactionCommissionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TransactionCommissionService();

        Dataset::create(['name' => 'merchant_commission_percentage', 'value' => '10']);
        Dataset::create([
            'name' => 'paymongo_payment_rates',
            'value' => json_encode([
                ['name' => 'qrph', 'value' => 1.34],
                ['name' => 'card', 'value' => 2.9],
                ['name' => 'gcash', 'value' => 2.23],
                ['name' => 'grab_pay', 'value' => 1.96],
                ['name' => 'shopee_pay', 'value' => 1.7],
                ['name' => 'billease', 'value' => 1.5],
                ['name' => 'paymaya', 'value' => 1.79],
                ['name' => 'dob', 'value' => 1.0],
                ['name' => 'dob_fixed_minimum', 'value' => 25],
                ['name' => 'brankas', 'value' => 1.34],
            ]),
        ]);
        Dataset::create([
            'name' => 'paypal_payment_rates',
            'value' => json_encode([
                ['name' => 'paypal_fee', 'value' => 3.9],
                ['name' => 'additional_fee', 'value' => 15],
            ]),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function methodUsed(string $method): array
    {
        return ['data' => ['attributes' => ['payment_method_used' => $method]]];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: float}>
     */
    public static function paymongoMethods(): array
    {
        return [
            'grab_pay' => ['grab_pay', 'grab_pay', 19.60],
            'shopee_pay' => ['shopee_pay', 'shopee_pay', 17.00],
            'billease' => ['billease', 'billease', 15.00],
            'paymaya' => ['paymaya', 'paymaya', 17.90],
            'qr_ph alias' => ['qr_ph', 'qrph', 13.40],
        ];
    }

    #[Test]
    #[DataProvider('paymongoMethods')]
    public function itSnapshotsTheRateForEachPaymongoMethod(string $raw, string $expectedKey, float $expectedFee): void
    {
        $tx = $this->makeTx([
            'payment_provider' => 'paymongo',
            'total_amount' => 1000,
            'payment_data' => $this->methodUsed($raw),
        ]);

        $record = $this->service->recordPaidTransaction($tx->fresh());

        $this->assertSame($expectedKey, $record->payment_method);
        $this->assertEquals($expectedFee, (float) $record->payment_gateway_commission);
        $this->assertEquals(0.0, (float) $record->payment_gateway_fixed_fee);
        $this->assertEquals(round(100 - $expectedFee, 2), (float) $record->ticketoc_net_commission);
    }

    #[Test]
    public function dobBelowTheFixedMinimumIsChargedTheMinimumAsAFixedFee(): void
    {
        $tx = $this->makeTx([
            'payment_provider' => 'paymongo',
            'total_amount' => 1000,
            'payment_data' => $this->methodUsed('dob_ubp'),
        ]);

        $record = $this->service->recordPaidTransaction($tx->fresh());

        // 1000 × 1% = 10 < 25 minimum → percent column 0, fixed column 25.
        $this->assertSame('dob', $record->payment_method);
        $this->assertEquals(1.0, (float) $record->payment_gateway_commission_percent);
        $this->assertEquals(0.0, (float) $record->payment_gateway_commission);
        $this->assertEquals(25.0, (float) $record->payment_gateway_fixed_fee);
        $this->assertEquals(75.0, (float) $record->ticketoc_net_commission);
    }

    #[Test]
    public function dobAboveTheFixedMinimumIsChargedThePercentage(): void
    {
        $tx = $this->makeTx([
            'payment_provider' => 'paymongo',
            'total_amount' => 5000,
            'payment_data' => $this->methodUsed('direct_debit'),
        ]);

        $record = $this->service->recordPaidTransaction($tx->fresh());

        $this->assertEquals(50.0, (float) $record->payment_gateway_commission);
        $this->assertEquals(0.0, (float) $record->payment_gateway_fixed_fee);
    }

    #[Test]
    public function paymongoWithoutPaymentDataRecordsNoGatewayFee(): void
    {
        $tx = $this->makeTx(['payment_provider' => 'paymongo', 'total_amount' => 1000, 'payment_data' => null]);

        $record = $this->service->recordPaidTransaction($tx->fresh());

        $this->assertNull($record->payment_method);
        $this->assertEquals(0.0, (float) $record->payment_gateway_commission);
        $this->assertEquals(100.0, (float) $record->ticketoc_net_commission);
    }

    #[Test]
    public function paymongoWithUnknownMethodRecordsNoGatewayFee(): void
    {
        $tx = $this->makeTx([
            'payment_provider' => 'paymongo',
            'total_amount' => 1000,
            'payment_data' => $this->methodUsed('crypto'),
        ]);

        $record = $this->service->recordPaidTransaction($tx->fresh());

        $this->assertNull($record->payment_method);
        $this->assertEquals(0.0, (float) $record->payment_gateway_commission_percent);
    }

    #[Test]
    public function organizationOverrideIsUsedAndRecordedAsTheCommissionSource(): void
    {
        $org = $this->makeOrg('Override Org', 7.5);
        $event = $this->makeEvent($org, null);
        $tx = $this->makeTx([
            'event_uuid' => $event->uuid,
            'organization_uuid' => null,
            'payment_provider' => 'paypal',
            'total_amount' => 2000,
        ]);

        $record = $this->service->recordPaidTransaction($tx->fresh());

        $this->assertEquals(7.5, (float) $record->ticketoc_commission_percent);
        $this->assertEquals(150.0, (float) $record->ticketoc_commission);
        $this->assertEquals(1850.0, (float) $record->net_amount);
        // organization_uuid falls back to the event's organization.
        $this->assertSame($org->uuid, $record->organization_uuid);
        $this->assertSame('organization.commission_percentage', data_get($record->metadata, 'organization_commission_source'));
    }

    #[Test]
    public function currencyIsReadFromThePaymentPayload(): void
    {
        $tx = $this->makeTx([
            'payment_provider' => 'paypal',
            'payment_data' => ['purchase_units' => [['amount' => ['currency_code' => 'usd']]]],
        ]);

        $record = $this->service->recordPaidTransaction($tx->fresh());

        $this->assertSame('USD', $record->currency);
    }

    #[Test]
    public function currencyDefaultsToPhpWhenThePayloadHasNone(): void
    {
        $tx = $this->makeTx(['payment_provider' => 'paypal', 'payment_data' => ['id' => 'x']]);

        $this->assertSame('PHP', $this->service->recordPaidTransaction($tx->fresh())->currency);
    }

    #[Test]
    public function persistenceFailuresAreLoggedAndSwallowed(): void
    {
        Log::spy();

        // An unsaved transaction has no uuid, so the NOT NULL accountable_id insert fails.
        $tx = new Transaction([
            'payment_provider' => 'paypal',
            'payment_status' => Transaction::PAYMENT_STATUS['PAID'],
            'total_amount' => 500,
        ]);

        $this->assertNull($this->service->recordPaidTransaction($tx));
        $this->assertSame(0, TransactionCommission::count());
        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(fn (string $message, array $context) => $message === 'Transaction commission recording failed'
                && $context['transaction_uuid'] === null
                && $context['error'] !== '');
    }

    #[Test]
    public function refundOfZeroAmountIsSkipped(): void
    {
        $tx = $this->makeTx(['total_amount' => 0, 'status' => Transaction::STATUS['REFUNDED']]);

        $this->assertNull($this->service->recordRefundedTransaction($tx->fresh()));
        $this->assertSame(0, TransactionCommission::count());
    }

    #[Test]
    public function refundRowSnapshotsTheOrganizationOverride(): void
    {
        $org = $this->makeOrg('Refund Org', 12);
        $event = $this->makeEvent($org, null);
        $tx = $this->makeTx([
            'event_uuid' => $event->uuid,
            'payment_provider' => 'paymongo',
            'payment_id' => 'pay_refund_1',
            'total_amount' => -800,
            'status' => Transaction::STATUS['REFUNDED'],
        ]);

        $record = $this->service->recordRefundedTransaction($tx->fresh(), '2026-01-15 10:00:00');

        $this->assertSame(TransactionCommission::TYPE['REFUND'], $record->transaction_type);
        $this->assertEquals(800.0, (float) $record->gross_amount);
        $this->assertEquals(12.0, (float) $record->ticketoc_commission_percent);
        $this->assertSame('paymongo', $record->payment_provider);
        $this->assertSame('pay_refund_1', $record->payment_id);
        $this->assertSame($org->uuid, $record->organization_uuid);
        $this->assertSame($tx->uuid, data_get($record->metadata, 'refund_for'));
    }

    #[Test]
    public function refundWithoutProviderIsLabelledRefundAndUsesThePlatformDefault(): void
    {
        $tx = $this->makeTx([
            'payment_provider' => null,
            'total_amount' => 300,
            'status' => Transaction::STATUS['REFUNDED'],
        ]);

        $record = $this->service->recordRefundedTransaction($tx->fresh());

        $this->assertSame('refund', $record->payment_provider);
        $this->assertEquals(10.0, (float) $record->ticketoc_commission_percent);
        $this->assertNotNull($record->date_paid);
    }

    #[Test]
    public function refundPersistenceFailuresAreLoggedAndSwallowed(): void
    {
        Log::spy();

        $tx = new Transaction(['payment_provider' => 'paypal', 'total_amount' => 400]);

        $this->assertNull($this->service->recordRefundedTransaction($tx));
        $this->assertSame(0, TransactionCommission::count());
        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(fn (string $message) => $message === 'Refund commission recording failed');
    }
}
