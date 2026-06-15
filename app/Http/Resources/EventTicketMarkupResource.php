<?php

namespace App\Http\Resources;

use App\Constants\GeneralConstants;
use App\Models\EventTicket;
use App\Services\TicketMarkupService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventTicketMarkupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var EventTicket $ticket */
        $ticket = $this->resource;
        $basePrice = (float) $ticket->price;
        $baseDiscount = TicketMarkupService::lineBaseDiscount($ticket, 1);
        $unitMarkup = TicketMarkupService::unitMarkupAmount($ticket);
        $markupDiscount = TicketMarkupService::lineMarkupDiscount($ticket, 1, $unitMarkup);

        return [
            'uuid' => $ticket->uuid,
            'event_uuid' => $ticket->event_uuid,
            'code' => $ticket->code,
            'name' => $ticket->name,
            'status' => $ticket->status,
            'price' => $ticket->price,
            'discount_type' => $ticket->discount_type,
            'discount_value' => $ticket->discount_value,
            'markup_type' => $ticket->markup_type,
            'markup_value' => $ticket->markup_value,
            'unit_markup' => $unitMarkup,
            'display_price' => TicketMarkupService::displayUnitPrice($ticket),
            'base_discount_per_unit' => $baseDiscount,
            'markup_discount_per_unit' => $markupDiscount,
            'merchant_discount_label' => $this->merchantDiscountLabel($ticket),
        ];
    }

    private function merchantDiscountLabel(EventTicket $ticket): ?string
    {
        if (! $ticket->discount_type || ! $ticket->discount_value) {
            return null;
        }

        if ($ticket->discount_type === GeneralConstants::DISCOUNT_TYPES['PERCENTAGE']) {
            $pct = rtrim(rtrim(number_format((float) $ticket->discount_value, 2, '.', ''), '0'), '.');

            return "{$pct}% off base price";
        }

        $amount = rtrim(rtrim(number_format((float) $ticket->discount_value, 2, '.', ''), '0'), '.');

        return "₱{$amount} off base price";
    }
}
