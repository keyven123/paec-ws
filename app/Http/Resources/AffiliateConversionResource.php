<?php

namespace App\Http\Resources;

use App\Models\AffiliateConversion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AffiliateConversionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'partner_user_uuid' => $this->partner_user_uuid,
            'transaction_uuid' => $this->transaction_uuid,
            'entry_type' => $this->entry_type ?? AffiliateConversion::ENTRY_TYPE_CREDIT,
            'ticket_uuid' => $this->ticket_uuid,
            'event_uuid' => $this->event_uuid,
            'order_total' => (float) $this->order_total,
            'commission_percent' => (float) $this->commission_percent,
            'commission_amount' => (float) $this->commission_amount,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'event' => $this->whenLoaded('event', function () {
                return [
                    'uuid' => $this->event->uuid,
                    'event_name' => $this->event->event_name,
                ];
            }),
            'transaction' => $this->whenLoaded('transaction', function () {
                return [
                    'uuid' => $this->transaction->uuid,
                    'order_number' => $this->transaction->order_number,
                    'paid_at' => $this->transaction->paid_at?->toIso8601String(),
                ];
            }),
            'ticket' => $this->whenLoaded('ticket', function () {
                if (!$this->ticket) {
                    return null;
                }

                return [
                    'uuid' => $this->ticket->uuid,
                    'ticket_number' => $this->ticket->ticket_number,
                ];
            }),
        ];
    }
}
