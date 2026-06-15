<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VenueResource extends JsonResource
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
            'name' => $this->name,
            'code' => $this->code,
            'type' => $this->type,
            'image_uuid' => $this->image_uuid,
            'tickets' => $this->tickets,
            'status' => $this->status,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),

            // Relationships
            'creator' => $this->whenLoaded('creator', function () {
                return [
                    'uuid' => $this->creator->uuid,
                    'name' => $this->creator->first_name . ' ' . $this->creator->last_name,
                    'email' => $this->creator->email,
                ];
            }),
            'updater' => $this->whenLoaded('updater', function () {
                return [
                    'uuid' => $this->updater->uuid,
                    'name' => $this->updater->first_name . ' ' . $this->updater->last_name,
                    'email' => $this->updater->email,
                ];
            }),

            // Computed fields
            'events_count' => $this->whenCounted('events'),
        ];
    }
}
