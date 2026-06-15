<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VenueSeatResource extends JsonResource
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
            'venue_uuid' => $this->venue_uuid,
            'col' => $this->col,
            'row' => $this->row,
            'seat_no' => $this->seat_no,
            'category' => $this->category,
            'color' => $this->color,
            'status' => $this->status,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            
            // Relationships
            'venue' => $this->whenLoaded('venue', function () {
                return [
                    'uuid' => $this->venue->uuid,
                    'name' => $this->venue->name,
                    'code' => $this->venue->code,
                ];
            }),
            'creator' => $this->whenLoaded('creator', function () {
                return [
                    'uuid' => $this->creator->uuid,
                    'name' => $this->creator->first_name . ' ' . $this->creator->last_name,
                ];
            }),
            'ticket_seats_count' => $this->whenCounted('ticketSeats'),
        ];
    }
}
