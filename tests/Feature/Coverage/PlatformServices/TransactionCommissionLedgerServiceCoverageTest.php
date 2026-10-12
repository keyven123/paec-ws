<?php

namespace Tests\Feature\Coverage\PlatformServices;

use App\Models\TransactionCommission;
use App\Services\Platform\TransactionCommissionLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TransactionCommissionLedgerServiceCoverageTest extends TestCase
{
    use PlatformFixtures;
    use RefreshDatabase;

    #[Test]
    public function summaryGroupsByProviderAndMethodAndSkipsBlankProvidersInCashTotals(): void
    {
        $this->ledgerRow([
            'payment_provider' => 'paymongo',
            'payment_method' => 'gcash',
            'gross_amount' => 1000,
            'net_amount' => 900,
            'ticketoc_commission' => 100,
            'ticketoc_net_commission' => 77.7,
            'payment_gateway_commission' => 22.3,
            'date_paid' => $this->at('2026-04-10 10:00:00'),
        ]);
        $this->ledgerRow([
            'payment_provider' => 'paypal',
            'gross_amount' => 2000,
            'net_amount' => 1800,
            'ticketoc_commission' => 200,
            'ticketoc_net_commission' => 107,
            'payment_gateway_commission' => 78,
            'payment_gateway_fixed_fee' => 15,
            'agent_commission' => 0,
            'date_paid' => $this->at('2026-04-11 10:00:00'),
        ]);
        // Legacy row without a provider: counted in totals, excluded from cash_by_provider.
        $this->ledgerRow([
            'payment_provider' => '',
            'gross_amount' => 50,
            'net_amount' => 45,
            'ticketoc_commission' => 5,
            'ticketoc_net_commission' => 5,
            'date_paid' => $this->at('2026-04-12 10:00:00'),
        ]);
        // Outside the range and a non-sale row: both ignored.
        $this->ledgerRow(['gross_amount' => 999, 'date_paid' => $this->at('2026-05-02 10:00:00')]);
        $this->ledgerRow([
            'gross_amount' => 777,
            'transaction_type' => TransactionCommission::TYPE['REFUND'],
            'date_paid' => $this->at('2026-04-10 10:00:00'),
        ]);

        $summary = (new TransactionCommissionLedgerService())->summary(
            $this->at('2026-04-01 00:00:00'),
            $this->at('2026-04-30 23:59:59')
        );

        $this->assertSame(3, $summary['totals']['transaction_count']);
        $this->assertSame(3050.0, $summary['totals']['gross_amount']);
        $this->assertSame(2745.0, $summary['totals']['net_amount']);
        $this->assertSame(305.0, $summary['totals']['ticketoc_commission']);
        $this->assertSame(189.7, $summary['totals']['ticketoc_net_commission']);
        $this->assertSame(100.3, $summary['totals']['payment_gateway_commission']);
        $this->assertSame(15.0, $summary['totals']['payment_gateway_fixed_fee']);
        $this->assertSame(115.3, $summary['totals']['payment_gateway_total']);

        $this->assertSame(['paypal' => 1907.0, 'paymongo' => 977.7], $summary['cash_by_provider']);

        $this->assertSame(['paypal', 'paymongo', ''], array_column($summary['by_provider'], 'payment_provider'));
        $this->assertSame(93.0, $summary['by_provider'][0]['payment_gateway_total']);
        $this->assertArrayNotHasKey('payment_method', $summary['by_provider'][0]);

        $this->assertSame([null, 'gcash', null], array_column($summary['by_method'], 'payment_method'));
        $this->assertSame('paymongo', $summary['by_method'][1]['payment_provider']);
        $this->assertSame(1, $summary['by_method'][1]['transaction_count']);
        $this->assertSame('2026-04-01T00:00:00+08:00', $summary['range']['from']);
    }

    #[Test]
    public function summaryOfAnEmptyRangeIsAllZeros(): void
    {
        $summary = (new TransactionCommissionLedgerService())->summary(
            $this->at('2026-04-01 00:00:00'),
            $this->at('2026-04-30 23:59:59')
        );

        $this->assertSame(0, $summary['totals']['transaction_count']);
        $this->assertSame(0.0, $summary['totals']['gross_amount']);
        $this->assertSame(0.0, $summary['totals']['payment_gateway_total']);
        $this->assertSame([], $summary['cash_by_provider']);
        $this->assertSame([], $summary['by_provider']);
        $this->assertSame([], $summary['by_method']);
    }
}
