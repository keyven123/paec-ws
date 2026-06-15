<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TempTransactionOrderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'user_uuid' => $this->user_uuid,
            'temp_transaction_uuid' => $this->temp_transaction_uuid,
            'event_ticket_uuid' => $this->event_ticket_uuid,
            'quantity' => $this->quantity,
            'price' => $this->price,
            'markup' => $this->markup ?? 0,
            'markup_discount' => $this->markup_discount ?? 0,
            'line_markup_gross' => round(
                (float) ($this->markup ?? 0) + (float) ($this->markup_discount ?? 0),
                2,
            ),
            'display_unit_price' => round(
                (float) $this->price + (
                    ((float) ($this->markup ?? 0) + (float) ($this->markup_discount ?? 0))
                    / max(1, (int) $this->quantity)
                ),
                2,
            ),
            'discount' => $this->discount,
            'total_amount' => $this->total_amount,
            'valid_until' => $this->valid_until?->format('Y-m-d'),
            'seats' => $this->seats,
            'event_ticket' => $this->eventTicket ? [
                    'uuid' => $this->eventTicket->uuid,
                    'name' => $this->eventTicket->name,
                    'code' => $this->eventTicket->code,
                    'price' => $this->eventTicket->price,
                    'is_bundle' => $this->eventTicket->is_bundle,
                    'bundle_quantity' => $this->eventTicket->bundle_quantity,
            ] : null,
        ];
    }
}
