<?php

namespace Tests\Unit;

use App\Constants\GeneralConstants;
use App\Helpers\ComputationHelper;
use App\Models\Event;
use App\Models\EventTicket;
use App\Models\PromoCode;
use App\Services\TicketMarkupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComputationHelperPromoTest extends TestCase
{
    use RefreshDatabase;

    public function test_promo_eligible_cart_total_includes_markup(): void
    {
        $ticket = new EventTicket([
            'price' => 100.0,
            'markup_type' => GeneralConstants::DISCOUNT_TYPES['PERCENTAGE'],
            'markup_value' => 10.0,
        ]);

        $line = TicketMarkupService::buildOrderLine($ticket, 1);
        $cartPreview = [
            'total_amount' => 100.0,
            'total_discount' => 0.0,
            'markup_amount' => 10.0,
            'temp_transaction_orders' => [$line],
        ];

        $this->assertSame(110.0, ComputationHelper::promoEligibleCartTotal($cartPreview));
    }

    public function test_percentage_promo_discount_applies_to_markup_included_total(): void
    {
        $event = Event::factory()->create();
        $ticket = EventTicket::create([
            'event_uuid' => $event->uuid,
            'code' => 'PROMO-MU',
            'name' => 'Markup Ticket',
            'price' => 100.0,
            'markup_type' => GeneralConstants::DISCOUNT_TYPES['PERCENTAGE'],
            'markup_value' => 10.0,
            'is_bundle' => false,
        ]);

        $cartPreview = ComputationHelper::generateTempTransactionData([
            ['event_ticket_uuid' => $ticket->uuid, 'quantity' => 1],
        ]);

        $promoCode = PromoCode::make([
            'discount_type' => GeneralConstants::DISCOUNT_TYPES['PERCENTAGE'],
            'discount_value' => 10.0,
        ]);

        $eligibleTotal = ComputationHelper::promoEligibleCartTotal($cartPreview);
        $discount = ComputationHelper::calculatePromoCodeDiscount($promoCode, $eligibleTotal);

        $this->assertSame(110.0, $eligibleTotal);
        $this->assertSame(11.0, $discount);
        $this->assertNotSame(10.0, $discount);
    }
}
