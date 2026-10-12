<?php

namespace Tests\Feature\Coverage\OrganizerServices;

use App\Models\Dataset;
use App\Models\Event;
use App\Models\MerchantPayoutRequest;
use App\Models\Organization;
use App\Models\Transaction;
use App\Services\Organizer\OrganizerAccountingBalanceService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrganizerAccountingBalanceServiceCoverageTest extends TestCase
{
    use OrganizerServiceFixtures;
    use RefreshDatabase;

    private OrganizerAccountingBalanceService $service;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeAt('2026-05-20 12:00:00');
        $this->service = app(OrganizerAccountingBalanceService::class);
        $this->org = $this->makeOrg('Balance Merchant', 10);
    }

    protected function tearDown(): void
    {
        Date::useDefault();
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function commissionRateUsesOrganizationOverrideOrPlatformDefault(): void
    {
        Dataset::create(['name' => 'merchant_commission_percentage', 'value' => '12.5']);

        $this->assertSame(7.5, $this->service->commissionRate($this->makeOrg('Override', 7.5)));
        $this->assertSame(12.5, $this->service->commissionRate($this->makeOrg('No Override', null)));
        $this->assertSame(12.5, $this->service->commissionRate(null));
    }

    #[Test]
    public function transactionAmountHelpersSeparateTaxDiscountAndCommission(): void
    {
        $tx = $this->makePaidTx($this->org, null, 1000, '2026-05-01 10:00:00', [
            'discount' => 50,
            'tax_amount' => 120,
            'total_amount' => 1070,
        ]);

        $this->assertSame(950.0, $this->service->baseAmountForTransaction($tx));
        $this->assertSame(120.0, $this->service->taxAndFeesForTransaction($tx));
        $this->assertSame(950.0, $this->service->commissionableGrossForTransaction($tx));
        $this->assertSame(95.0, $this->service->commissionAmountForTransaction($tx, 10));
        // Legacy line: 1000 − 50 discount = 950 net selling, minus 10% platform fee.
        $this->assertSame(855.0, $this->service->merchantNetForTransaction($tx, 10));
    }

    #[Test]
    public function transactionAmountHelpersTreatMissingTaxAndDiscountAsZero(): void
    {
        $tx = new Transaction(['sub_total' => 300, 'total_amount' => 300]);

        $this->assertSame(300.0, $this->service->baseAmountForTransaction($tx));
        $this->assertSame(0.0, $this->service->taxAndFeesForTransaction($tx));
        $this->assertSame(300.0, $this->service->commissionableGrossForTransaction($tx));
        $this->assertSame(15.0, $this->service->commissionAmountForTransaction($tx, 5));
    }

    #[Test]
    public function exportLinesSplitAffiliateCommissionAcrossOrderLines(): void
    {
        $event = $this->makeEvent($this->org, 'Lines Event');
        $tx = $this->makePaidTx($this->org, $event, 1500, '2026-05-02 10:00:00');
        $orderA = $this->addOrder($tx, 600, 2);
        $orderB = $this->addOrder($tx, 300, 1);
        $this->addAffiliateCommission($tx, 150);

        $lines = $this->service->merchantExportLinesForTransaction($tx->fresh(), 10);

        $this->assertCount(2, $lines);
        $byUuid = collect($lines)->keyBy('uuid');

        $lineA = $byUuid[$orderA->uuid];
        $this->assertSame($tx->uuid, $lineA['transaction_uuid']);
        $this->assertSame(2, $lineA['quantity']);
        $this->assertSame(600.0, $lineA['unit_price']);
        $this->assertSame(1200.0, $lineA['net_selling_price']);
        $this->assertSame(120.0, $lineA['platform_fee']);
        $this->assertSame(120.0, $lineA['affiliate_commission']);
        $this->assertSame(960.0, $lineA['total_payout']);

        $lineB = $byUuid[$orderB->uuid];
        $this->assertSame(1, $lineB['quantity']);
        $this->assertSame(300.0, $lineB['net_selling_price']);
        $this->assertSame(30.0, $lineB['platform_fee']);
        $this->assertSame(30.0, $lineB['affiliate_commission']);
        $this->assertSame(240.0, $lineB['total_payout']);

        $this->assertSame(1200.0, $this->service->merchantTotalPayoutForTransaction($tx->fresh(), 10));
    }

    #[Test]
    public function exportLinesSkipOrderLinesWithoutPositivePayout(): void
    {
        $event = $this->makeEvent($this->org, 'Comp Event');
        $tx = $this->makePaidTx($this->org, $event, 900, '2026-05-02 10:00:00');
        $this->addOrder($tx, 500, 1, ['discount' => 500, 'total_amount' => 0]);
        $paid = $this->addOrder($tx, 400, 1);

        $lines = $this->service->merchantExportLinesForTransaction($tx->fresh(), 10);

        $this->assertCount(1, $lines);
        $this->assertSame($paid->uuid, $lines[0]['uuid']);
        $this->assertSame(360.0, $lines[0]['total_payout']);
        $this->assertSame(0.0, $lines[0]['affiliate_commission']);
    }

    #[Test]
    public function exportLinesFallBackToLegacyLineWhenNoOrderLineHasPayout(): void
    {
        $event = $this->makeEvent($this->org, 'Free Lines Event');
        $tx = $this->makePaidTx($this->org, $event, 800, '2026-05-02 10:00:00');
        $this->addOrder($tx, 500, 1, ['discount' => 500, 'total_amount' => 0]);

        $lines = $this->service->merchantExportLinesForTransaction($tx->fresh(), 10);

        $this->assertSame([[
            'uuid' => $tx->uuid,
            'transaction_uuid' => $tx->uuid,
            'quantity' => 1,
            'unit_price' => 800.0,
            'discount' => 0.0,
            'net_selling_price' => 800.0,
            'affiliate_commission' => 0.0,
            'platform_fee' => 80.0,
            'total_payout' => 720.0,
        ]], $lines);
    }

    #[Test]
    public function exportLinesAreEmptyWhenOrderLinesReferenceMissingEvent(): void
    {
        $tx = $this->makePaidTx($this->org, null, 500, '2026-05-02 10:00:00', [
            'event_uuid' => (string) Str::uuid(),
        ]);
        $this->addOrder($tx, 500, 1);

        $this->assertSame([], $this->service->merchantExportLinesForTransaction($tx->fresh(), 10));
        $this->assertSame(0.0, $this->service->merchantTotalPayoutForTransaction($tx->fresh(), 10));
    }

    #[Test]
    public function remittanceTotalsAndAvailableBalanceAggregateEventsUnassignedAndPayouts(): void
    {
        $eventA = $this->makeEvent($this->org, 'Event A');
        $eventB = $this->makeEvent($this->org, 'Event B');

        // Matured (paid 1st–15th → 30th of the month).
        $this->makePaidTx($this->org, $eventA, 1000, '2026-04-03 10:00:00');
        // Pending (paid 16th–end → 15th of next month).
        $this->makePaidTx($this->org, $eventB, 2000, '2026-05-17 10:00:00');
        // Unassigned (no event), matured on May 15.
        $this->makePaidTx($this->org, null, 500, '2026-04-20 10:00:00');
        // Zero merchant net is ignored.
        $this->makePaidTx($this->org, $eventA, 0, '2026-04-04 10:00:00');
        // Excluded: refunded, unpaid, other organization.
        $this->makePaidTx($this->org, $eventA, 7000, '2026-04-05 10:00:00', ['status' => Transaction::STATUS['REFUNDED']]);
        $this->makePaidTx($this->org, $eventA, 7000, '2026-04-05 10:00:00', ['payment_status' => Transaction::PAYMENT_STATUS['PENDING']]);
        $other = $this->makeOrg('Other Merchant', 10);
        $this->makePaidTx($other, $this->makeEvent($other, 'Other Event'), 7000, '2026-04-05 10:00:00');

        $this->assertSame([1800.0, 1350.0], $this->service->remittanceTotals($this->org->uuid, 10));
        $this->assertSame([0.0, 900.0], $this->service->remittanceTotals($this->org->uuid, 10, $eventA->uuid));
        $this->assertSame([1800.0, 0.0], $this->service->remittanceTotals($this->org->uuid, 10, $eventB->uuid));

        $this->makePayout($this->org, $eventA, 200, MerchantPayoutRequest::STATUS_APPROVED);
        $this->makePayout($this->org, $eventA, 100, MerchantPayoutRequest::STATUS_PENDING);
        $this->makePayout($this->org, $eventA, 999, MerchantPayoutRequest::STATUS_APPROVED, ['void_at' => now()]);
        $this->makePayout($this->org, $eventA, 77, MerchantPayoutRequest::STATUS_DECLINED);
        $this->makePayout($this->org, null, 50, MerchantPayoutRequest::STATUS_APPROVED);
        // Payout tied to an event that no longer belongs to the organization counts as organization-wide.
        MerchantPayoutRequest::query()->create($this->merchantPayoutAttributes($this->org, [
            'event_uuid' => (string) Str::uuid(),
            'amount_requested' => 25,
            'status' => MerchantPayoutRequest::STATUS_PENDING,
        ]));

        $this->assertSame(600.0, $this->service->availableForPayout($this->org->uuid, $eventA->uuid));
        $this->assertSame(0.0, $this->service->availableForPayout($this->org->uuid, $eventB->uuid));
        // 600 (A) + 0 (B) + 450 unassigned matured − 50 approved − 25 pending organization-wide.
        $this->assertSame(975.0, $this->service->availableForPayout($this->org->uuid));
    }

    #[Test]
    public function availableBalanceForOrganizationWithoutEventsUsesOnlyUnassignedRevenue(): void
    {
        $org = $this->makeOrg('Eventless Merchant', 10);
        $this->makePaidTx($org, null, 1000, '2026-04-02 10:00:00');
        $this->makePayout($org, null, 100, MerchantPayoutRequest::STATUS_APPROVED);

        $this->assertSame([0.0, 900.0], $this->service->remittanceTotals($org->uuid, 10));
        $this->assertSame(800.0, $this->service->availableForPayout($org->uuid));
    }

    #[Test]
    public function remittanceScheduleIsNotPendingWithoutAnyPaymentTimestamp(): void
    {
        $this->assertFalse($this->service->remittanceScheduleIsPending(new Transaction(), Carbon::now($this->tz())->startOfDay()));
    }

    #[Test]
    public function remittanceScheduleFallsBackToCreatedAtWhenPaidAtIsMissing(): void
    {
        $tx = $this->makePaidTx($this->org, null, 100, '2026-05-01 10:00:00');
        $this->setTxColumns($tx, ['paid_at' => null, 'created_at' => '2026-05-18 10:00:00']);
        $today = Carbon::now($this->tz())->startOfDay();

        $this->assertTrue($this->service->remittanceScheduleIsPending($tx->fresh(), $today));

        $this->setTxColumns($tx, ['created_at' => '2026-04-02 10:00:00']);
        $this->assertFalse($this->service->remittanceScheduleIsPending($tx->fresh(), $today));
    }

    #[Test]
    public function remittanceScheduleParsesImmutableDatesFromTheModel(): void
    {
        $pendingTx = $this->makePaidTx($this->org, null, 100, '2026-05-17 10:00:00');
        $maturedTx = $this->makePaidTx($this->org, null, 100, '2026-04-03 10:00:00');
        $today = Carbon::now($this->tz())->startOfDay();

        Date::use(CarbonImmutable::class);

        $pending = $pendingTx->fresh();
        $this->assertInstanceOf(CarbonImmutable::class, $pending->paid_at);
        $this->assertTrue($this->service->remittanceScheduleIsPending($pending, $today));
        $this->assertFalse($this->service->remittanceScheduleIsPending($maturedTx->fresh(), $today));
    }
}
