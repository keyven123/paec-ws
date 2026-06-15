<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventLocationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'event_uuid' => $this->event_uuid,
            'name' => $this->name,
            'city' => $this->city,
            'address' => $this->address,
            'label' => $this->displayLabel(),
            'organization_uuid' => $this->organization_uuid,
            'is_active' => (bool) $this->is_active,
            'sort_order' => (int) $this->sort_order,
            'organization' => $this->whenLoaded('organization', function () {
                return [
                    'uuid' => $this->organization->uuid,
                    'name' => $this->organization->name,
                ];
            }),
            'total_orders' => $this->when(isset($this->total_orders), fn () => (int) $this->total_orders),
            'total_amount' => $this->when(isset($this->total_amount), fn () => (float) $this->total_amount),
            'ticket_sold' => $this->when(isset($this->ticket_sold), fn () => (int) $this->ticket_sold),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
