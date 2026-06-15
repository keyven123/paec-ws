<?php

namespace Tests\Unit;

use App\Constants\GeneralConstants;
use App\Models\ActivityCompliance;
use App\Models\Event;
use App\Models\EventTicket;
use App\Services\ActivityComplianceService;
use App\Services\TicketMarkupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketMarkupServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_percentage_markup_on_pre_discount_base(): void
    {
        $ticket = $this->makeTicket(100.0, GeneralConstants::DISCOUNT_TYPES['PERCENTAGE'], 10.0);

        $this->assertSame(10.0, TicketMarkupService::unitMarkupAmount($ticket));
        $this->assertSame(110.0, TicketMarkupService::displayUnitPrice($ticket));
    }

    public function test_fixed_amount_markup_alias_amount(): void
    {
        $ticket = $this->makeTicket(999.0, 'amount', 100.0);

        $line = TicketMarkupService::buildOrderLine($ticket, 1);
        $aggregated = TicketMarkupService::aggregateFromOrderLines([$line]);

        $this->assertSame(100.0, $line['markup']);
        $this->assertSame(100.0, $aggregated['markup_amount']);
        $this->assertSame(GeneralConstants::DISCOUNT_TYPES['AMOUNT'], $aggregated['markup_type']);
        $this->assertSame(100.0, $aggregated['markup_value']);
    }

    public function test_checkout_total_includes_markup_after_merchandise_discount(): void
    {
        $event = Event::factory()->create();
        $ticket = $this->makeTicket(
            999.0,
            GeneralConstants::DISCOUNT_TYPES['AMOUNT'],
            100.0,
            GeneralConstants::DISCOUNT_TYPES['AMOUNT'],
            200.0,
        );

        $line = TicketMarkupService::buildOrderLine($ticket, 1);
        $aggregated = TicketMarkupService::aggregateFromOrderLines([$line]);

        $amounts = ActivityComplianceService::applyToCheckoutAmounts($event, [
            'sub_total' => $aggregated['sub_total'],
            'discount' => $aggregated['discount'],
            'promo_code_discount' => 0,
            'markup_amount' => $aggregated['markup_amount'],
        ]);

        $this->assertSame(999.0, $aggregated['sub_total']);
        $this->assertSame(200.0, $aggregated['discount']);
        $this->assertSame(100.0, $aggregated['markup_amount']);
        $this->assertSame(899.0, $amounts['total_amount']);
    }

    public function test_ticket_discount_applies_to_base_and_markup(): void
    {
        $ticket = $this->makeTicket(
            100.0,
            GeneralConstants::DISCOUNT_TYPES['PERCENTAGE'],
            10.0,
            GeneralConstants::DISCOUNT_TYPES['PERCENTAGE'],
            10.0,
        );

        $line = TicketMarkupService::buildOrderLine($ticket, 2);

        $this->assertSame(20.0, $line['discount']);
        $this->assertSame(2.0, $line['markup_discount']);
        $this->assertSame(18.0, $line['markup']);
        $this->assertSame(198.0, $line['total_amount']);
    }

    public function test_checkout_compliance_splits_merchandise_and_markup(): void
    {
        $event = Event::factory()->create();

        ActivityCompliance::query()->create([
            'activityable_type' => 'event',
            'activityable_id' => $event->uuid,
            'label' => 'Service Fee',
            'percentage' => 0,
            'fixed_amount' => 25,
            'amount_type' => ActivityCompliance::AMOUNT_TYPE['FIXED'],
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        ActivityCompliance::query()->create([
            'activityable_type' => 'event',
            'activityable_id' => $event->uuid,
            'label' => 'VAT',
            'percentage' => 12,
            'amount_type' => ActivityCompliance::AMOUNT_TYPE['PERCENTAGE'],
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        $result = ActivityComplianceService::applyToCheckoutAmounts($event, [
            'sub_total' => 100,
            'discount' => 0,
            'promo_code_discount' => 0,
            'markup_amount' => 10,
        ]);

        $this->assertSame(38.2, $result['tax_amount']);
        $this->assertSame(148.2, $result['total_amount']);
        $this->assertCount(3, $result['compliance_lines']);

        $appliesTo = array_column($result['compliance_lines'], 'applies_to');
        $this->assertSame(2, count(array_filter($appliesTo, fn ($v) => $v === 'merchandise')));
        $this->assertSame(1, count(array_filter($appliesTo, fn ($v) => $v === 'markup')));
    }

    private function makeTicket(
        float $price,
        ?string $markupType,
        ?float $markupValue,
        ?string $discountType = null,
        ?float $discountValue = null,
    ): EventTicket {
        return new EventTicket([
            'uuid' => fake()->uuid(),
            'event_uuid' => fake()->uuid(),
            'code' => 'T1',
            'name' => 'GA',
            'price' => $price,
            'markup_type' => $markupType,
            'markup_value' => $markupValue,
            'discount_type' => $discountType,
            'discount_value' => $discountValue,
        ]);
    }
}
