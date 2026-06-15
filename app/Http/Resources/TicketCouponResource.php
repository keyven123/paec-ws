<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketCouponResource extends JsonResource
{
    /**
     * Format date for API (handles Carbon or string from DB).
     */
    private function formatDate(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            return $value;
        }
        return $value->toDateTimeString();
    }

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
            'ticket_uuid' => $this->ticket_uuid,
            'event_uuid' => $this->event_uuid,
            'event_ticket_coupon_uuid' => $this->event_ticket_coupon_uuid,
            'name' => $this->name,
            'qr_code' => $this->qr_code,
            'status' => $this->status,
            'claimed_at' => $this->formatDate($this->claimed_at),
            'created_at' => $this->formatDate($this->created_at),
            'updated_at' => $this->formatDate($this->updated_at),

            'event' => $this->whenLoaded('event', function () {
                return [
                    'uuid' => $this->event->uuid,
                    'name' => $this->event->event_name ?? $this->event->name ?? null,
                ];
            }),

            'event_ticket_coupon' => $this->whenLoaded('eventTicketCoupon', function () {
                return [
                    'uuid' => $this->eventTicketCoupon->uuid,
                    'name' => $this->eventTicketCoupon->name,
                    'event_ticket_name' => $this->eventTicketCoupon->eventTicket->name,
                ];
            }),

            'ticket' => $this->whenLoaded('ticket', function () {
                return [
                    'uuid' => $this->ticket->uuid,
                    'ticket_code' => $this->ticket->ticket_code ?? null,
                ];
            }),

            'user' => $this->whenLoaded('user', function () {
                return [
                    'uuid' => $this->user->uuid,
                    'name' => $this->user->name ?? null,
                    'email' => $this->user->email ?? null,
                ];
            }),

            'scanned_by_user' => $this->whenLoaded('scannedBy', function () {
                return [
                    'uuid' => $this->scannedBy->uuid,
                    'first_name' => $this->scannedBy->first_name ?? null,
                ];
            }),
        ];
    }
}
