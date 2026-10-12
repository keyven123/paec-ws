<?php

namespace Tests\Feature\Coverage\RServicesJobs;

use App\Constants\GeneralConstants;
use App\Models\ActivityCompliance;
use App\Models\AffiliateConversion;
use App\Models\Event;
use App\Models\EventLocation;
use App\Models\EventTicket;
use App\Models\Ticket;
use App\Models\TransactionCompliance;
use App\Models\TransactionOrder;
use App\Services\ActivityComplianceService;
use App\Services\AffiliateSalesDeductionService;
use App\Services\EventLocationService;
use App\Services\TicketMarkupService;
use App\Services\TicketPurchasePricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Coverage\RServicesJobs\Concerns\CreatesRServicesJobsFixtures;
use Tests\TestCase;

class PricingServicesCoverageTest extends TestCase
{
    use CreatesRServicesJobsFixtures;
    use RefreshDatabase;

    private function affiliateEvent(array $attributes = []): Event
    {
        return (new Event())->forceFill(array_merge([
            'affiliate_enabled' => true,
            'affiliate_commission_percent' => 10,
        ], $attributes));
    }

    private function orderLine(string $uuid, float $price, int $quantity, float $totalAmount): TransactionOrder
    {
        return (new TransactionOrder())->forceFill([
            'uuid' => $uuid,
            'price' => $price,
            'quantity' => $quantity,
            'total_amount' => $totalAmount,
        ]);
    }

    // --- AffiliateSalesDeductionService ---

    #[Test]
    public function grossAffiliateDeductionIsZeroWithoutPartner(): void
    {
        $this->assertSame(0.0, AffiliateSalesDeductionService::grossAffiliateDeduction(null, 1000, $this->affiliateEvent(), null));
    }

    #[Test]
    public function grossAffiliateDeductionUsesRecordedCreditAmount(): void
    {
        $credit = (new AffiliateConversion())->forceFill(['commission_amount' => 123.456]);
        $negativeCredit = (new AffiliateConversion())->forceFill(['commission_amount' => -5]);

        $this->assertSame(123.46, AffiliateSalesDeductionService::grossAffiliateDeduction('partner', 1000, $this->affiliateEvent(), $credit));
        $this->assertSame(0.0, AffiliateSalesDeductionService::grossAffiliateDeduction('partner', 1000, $this->affiliateEvent(), $negativeCredit));
    }

    #[Test]
    public function grossAffiliateDeductionIsZeroWhenEventAffiliateProgramDisabled(): void
    {
        $event = $this->affiliateEvent(['affiliate_enabled' => false]);

        $this->assertSame(0.0, AffiliateSalesDeductionService::grossAffiliateDeduction('partner', 1000, $event, null));
    }

    #[Test]
    public function grossAffiliateDeductionIsZeroWithoutPositiveCommissionPercent(): void
    {
        $this->assertSame(0.0, AffiliateSalesDeductionService::grossAffiliateDeduction(
            'partner', 1000, $this->affiliateEvent(['affiliate_commission_percent' => null]), null,
        ));
        $this->assertSame(0.0, AffiliateSalesDeductionService::grossAffiliateDeduction(
            'partner', 1000, $this->affiliateEvent(['affiliate_commission_percent' => 0]), null,
        ));
    }

    #[Test]
    public function grossAffiliateDeductionIsZeroForNonPositiveTransactionTotal(): void
    {
        $this->assertSame(0.0, AffiliateSalesDeductionService::grossAffiliateDeduction('partner', 0, $this->affiliateEvent(), null));
    }

    #[Test]
    public function grossAffiliateDeductionComputesPercentOfTransactionTotal(): void
    {
        $this->assertSame(125.0, AffiliateSalesDeductionService::grossAffiliateDeduction(
            'partner', 1250, $this->affiliateEvent(['affiliate_commission_percent' => 10]), null,
        ));
    }

    #[Test]
    public function orderLineNetPrefersTotalAmountAndFallsBackToPriceTimesQuantity(): void
    {
        $this->assertSame(450.0, AffiliateSalesDeductionService::orderLineNet($this->orderLine('a', 500, 1, 450), 0));
        $this->assertSame(250.0, AffiliateSalesDeductionService::orderLineNet($this->orderLine('b', 100, 3, 0), 50));
        $this->assertSame(0.0, AffiliateSalesDeductionService::orderLineNet($this->orderLine('c', 10, 1, 0), 50));
    }

    #[Test]
    public function deductionPerOrderLineReturnsZerosForNoCommission(): void
    {
        $orders = collect([$this->orderLine('b', 100, 1, 100), $this->orderLine('a', 100, 1, 100)]);

        $this->assertSame(['a' => 0.0, 'b' => 0.0], AffiliateSalesDeductionService::deductionPerOrderLine($orders, 0));
        $this->assertSame([], AffiliateSalesDeductionService::deductionPerOrderLine(collect(), 50));
    }

    #[Test]
    public function deductionPerOrderLineReturnsZerosWhenLinesHaveNoNet(): void
    {
        $orders = collect([$this->orderLine('a', 0, 1, 0), $this->orderLine('b', 0, 2, 0)]);

        $this->assertSame(['a' => 0.0, 'b' => 0.0], AffiliateSalesDeductionService::deductionPerOrderLine($orders, 30));
    }

    #[Test]
    public function deductionPerOrderLineSplitsProportionallyWithRemainderOnLastLine(): void
    {
        $orders = collect([
            $this->orderLine('c', 100, 1, 100),
            $this->orderLine('a', 100, 1, 100),
            $this->orderLine('b', 100, 1, 100),
        ]);

        $result = AffiliateSalesDeductionService::deductionPerOrderLine($orders, 10);

        $this->assertSame(['a' => 3.33, 'b' => 3.33, 'c' => 3.34], $result);
        $this->assertEqualsWithDelta(10.0, array_sum($result), 0.0001);
    }

    // --- TicketMarkupService ---

    #[Test]
    public function unitMarkupAmountIsZeroForUnknownMarkupType(): void
    {
        $ticket = (new EventTicket())->forceFill(['price' => 100, 'markup_type' => 'bogus', 'markup_value' => 5]);

        $this->assertSame('bogus', TicketMarkupService::normalizeMarkupType(' Bogus '));
        $this->assertSame(0.0, TicketMarkupService::unitMarkupAmount($ticket));
        $this->assertSame(100.0, TicketMarkupService::displayUnitPrice($ticket));
    }

    #[Test]
    public function resolveHeaderMarkupSnapshotReportsMixedForMultipleSignatures(): void
    {
        $result = TicketMarkupService::resolveHeaderMarkupSnapshot([
            ['markup_type' => 'percentage', 'markup_value' => 10.0],
            ['markup_type' => 'amount', 'markup_value' => 25.0],
        ]);

        $this->assertSame(['markup_type' => TicketMarkupService::HEADER_MARKUP_TYPE_MIXED, 'markup_value' => null], $result);
    }

    #[Test]
    public function buildOrderLinesFromTicketPayloadsSkipsUnknownTickets(): void
    {
        $event = $this->createRsjEvent();
        $ticket = $this->createRsjEventTicket($event, ['price' => 200]);

        $lines = TicketMarkupService::buildOrderLinesFromTicketPayloads([
            ['event_ticket_uuid' => (string) Str::uuid(), 'quantity' => 1],
            ['event_ticket_uuid' => $ticket->uuid, 'quantity' => 2, 'valid_until' => '2026-12-31'],
        ]);

        $this->assertCount(1, $lines);
        $this->assertSame($ticket->uuid, $lines[0]['event_ticket_uuid']);
        $this->assertSame(2, $lines[0]['quantity']);
        $this->assertSame(400.0, $lines[0]['total_amount']);
        $this->assertSame('2026-12-31', $lines[0]['valid_until']);
        $this->assertNull($lines[0]['seats']);
    }

    // --- TicketPurchasePricingService ---

    #[Test]
    public function customerGrossRevenuePerTicketUsesTicketPriceWithoutTransaction(): void
    {
        $ticket = (new Ticket())->forceFill(['price' => 349.999]);

        $this->assertSame(350.0, TicketPurchasePricingService::customerGrossRevenuePerTicket($ticket));
    }

    #[Test]
    public function customerGrossRevenuePerTicketHandlesOrdersWithZeroLineTotals(): void
    {
        $customer = $this->createRsjUser();
        $event = $this->createRsjEvent();
        $eventTicket = $this->createRsjEventTicket($event, ['price' => 500]);
        $transaction = $this->createRsjTransaction($event, $customer, ['total_amount' => 500, 'promo_code_discount' => 50]);
        TransactionOrder::create([
            'user_uuid' => $customer->uuid,
            'transaction_uuid' => $transaction->uuid,
            'event_ticket_uuid' => $eventTicket->uuid,
            'quantity' => 1,
            'price' => 500,
            'discount' => 0,
            'total_amount' => 0,
        ]);
        $ticket = $this->createRsjTicket($transaction, $eventTicket);

        $this->assertSame(500.0, TicketPurchasePricingService::customerGrossRevenuePerTicket($ticket->fresh()));
    }

    #[Test]
    public function splitPercentagePromoDiscountAssignsAllToBaseWhenNothingIsEligible(): void
    {
        $order = (new TransactionOrder())->forceFill(['discount' => 0, 'markup' => 0, 'markup_discount' => 0]);

        $result = TicketPurchasePricingService::splitPercentagePromoDiscount(
            $order, 0, 1, 12.345, GeneralConstants::DISCOUNT_TYPES['PERCENTAGE'],
        );

        $this->assertSame(['base_promo' => 12.35, 'markup_promo' => 0.0], $result);
    }

    // --- ActivityComplianceService ---

    #[Test]
    public function recordForTransactionPersistsSnapshotsDefaultingToMerchandise(): void
    {
        $customer = $this->createRsjUser();
        $event = $this->createRsjEvent();
        $transaction = $this->createRsjTransaction($event, $customer);
        $rule = ActivityCompliance::create([
            'activityable_type' => 'event',
            'activityable_id' => $event->uuid,
            'label' => 'VAT',
            'percentage' => 12,
            'amount_type' => ActivityCompliance::AMOUNT_TYPE['PERCENTAGE'],
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        $lines = [
            ['activity_compliance_uuid' => $rule->uuid, 'percentage' => 12, 'amount' => 60],
            ['activity_compliance_uuid' => $rule->uuid, 'percentage' => 12, 'amount' => 6, 'applies_to' => TransactionCompliance::APPLIES_TO_MARKUP],
        ];
        ActivityComplianceService::recordForTransaction($transaction, $lines);
        ActivityComplianceService::recordForTransaction($transaction, $lines);

        $this->assertSame(2, TransactionCompliance::where('transaction_uuid', $transaction->uuid)->count());
        $this->assertDatabaseHas('transaction_compliances', [
            'transaction_uuid' => $transaction->uuid,
            'activity_compliance_uuid' => $rule->uuid,
            'applies_to' => TransactionCompliance::APPLIES_TO_MERCHANDISE,
            'amount' => 60,
        ]);
        $this->assertDatabaseHas('transaction_compliances', [
            'transaction_uuid' => $transaction->uuid,
            'applies_to' => TransactionCompliance::APPLIES_TO_MARKUP,
            'amount' => 6,
        ]);
    }

    #[Test]
    public function assertActivePercentageTotalWithinLimitIgnoresExcludedRule(): void
    {
        $event = $this->createRsjEvent();
        foreach ([['A', 60], ['B', 60]] as [$label, $pct]) {
            $rules[$label] = ActivityCompliance::create([
                'activityable_type' => 'event',
                'activityable_id' => $event->uuid,
                'label' => $label,
                'percentage' => $pct,
                'amount_type' => ActivityCompliance::AMOUNT_TYPE['PERCENTAGE'],
                'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            ]);
        }

        ActivityComplianceService::assertActivePercentageTotalWithinLimit($event, $rules['B']->uuid);

        $this->expectException(ValidationException::class);
        ActivityComplianceService::assertActivePercentageTotalWithinLimit($event);
    }

    #[Test]
    public function assertCandidateActivePercentageTotalSkipsInactiveAndFixedRows(): void
    {
        $event = $this->createRsjEvent();
        $base = ['activityable_type' => 'event', 'activityable_id' => $event->uuid];
        ActivityCompliance::create($base + [
            'label' => 'Inactive', 'percentage' => 90,
            'amount_type' => ActivityCompliance::AMOUNT_TYPE['PERCENTAGE'],
            'status' => GeneralConstants::GENERAL_STATUSES['INACTIVE'],
        ]);
        ActivityCompliance::create($base + [
            'label' => 'Fixed', 'percentage' => 90, 'fixed_amount' => 20,
            'amount_type' => ActivityCompliance::AMOUNT_TYPE['FIXED'],
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);
        ActivityCompliance::create($base + [
            'label' => 'VAT', 'percentage' => 12,
            'amount_type' => ActivityCompliance::AMOUNT_TYPE['PERCENTAGE'],
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        $okCandidate = new ActivityCompliance([
            'label' => 'Service', 'percentage' => 88,
            'amount_type' => ActivityCompliance::AMOUNT_TYPE['PERCENTAGE'],
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);
        ActivityComplianceService::assertCandidateActivePercentageTotal($event, $okCandidate);

        $tooHigh = new ActivityCompliance([
            'label' => 'Service', 'percentage' => 89,
            'amount_type' => ActivityCompliance::AMOUNT_TYPE['PERCENTAGE'],
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        try {
            ActivityComplianceService::assertCandidateActivePercentageTotal($event, $tooHigh);
            $this->fail('Expected the stacked percentage check to fail.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('percentage', $e->errors());
        }
    }

    #[Test]
    public function buildIncludedNoteFromSnapshotsFallsBackToGenericFeeLabels(): void
    {
        $percentSnapshot = (new TransactionCompliance())->forceFill([
            'uuid' => 's1', 'activity_compliance_uuid' => 'gone-1', 'percentage' => 5, 'amount' => 25,
        ])->setRelation('activityCompliance', null);
        $fixedSnapshot = (new TransactionCompliance())->forceFill([
            'uuid' => 's2', 'activity_compliance_uuid' => 'gone-2', 'percentage' => 0, 'amount' => 15.5,
        ])->setRelation('activityCompliance', null);

        $note = ActivityComplianceService::buildIncludedNoteFromSnapshots(collect([$percentSnapshot, $fixedSnapshot]));

        $this->assertSame('Included in price: Fee 5%, Fee 15.5', $note);
    }

    // --- EventLocationService ---

    #[Test]
    public function resolveForCheckoutFailsWhenAllLocationsAreInactive(): void
    {
        $event = $this->createRsjEvent();
        EventLocation::create(['event_uuid' => $event->uuid, 'city' => 'Cebu', 'is_active' => false, 'sort_order' => 0]);

        try {
            EventLocationService::resolveForCheckout($event, null);
            $this->fail('Expected a validation exception.');
        } catch (ValidationException $e) {
            $this->assertSame(
                ['No active locations are configured for this activity.'],
                $e->errors()['event_location_uuid'],
            );
        }
    }

    #[Test]
    public function resolveForCheckoutRejectsLocationFromAnotherActivity(): void
    {
        $event = $this->createRsjEvent();
        EventLocation::create(['event_uuid' => $event->uuid, 'city' => 'Cebu', 'is_active' => true, 'sort_order' => 0]);
        $otherEvent = $this->createRsjEvent();
        $foreign = EventLocation::create(['event_uuid' => $otherEvent->uuid, 'city' => 'Davao', 'is_active' => true, 'sort_order' => 0]);

        try {
            EventLocationService::resolveForCheckout($event, $foreign->uuid);
            $this->fail('Expected a validation exception.');
        } catch (ValidationException $e) {
            $this->assertSame(
                ['Selected location is not valid for this activity.'],
                $e->errors()['event_location_uuid'],
            );
        }
    }

    #[Test]
    public function ensureDefaultLocationReturnsExistingLocation(): void
    {
        $event = $this->createRsjEvent();
        $existing = EventLocation::create(['event_uuid' => $event->uuid, 'city' => 'Iloilo', 'is_active' => false, 'sort_order' => 3]);

        $resolved = EventLocationService::ensureDefaultLocation($event);

        $this->assertSame($existing->uuid, $resolved->uuid);
        $this->assertSame(1, EventLocation::where('event_uuid', $event->uuid)->count());
    }
}
