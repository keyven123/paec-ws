<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketSeatResource extends JsonResource
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
            'ticket_uuid' => $this->ticket_uuid,
            'venue_uuid' => $this->venue_uuid,
            'venue_seat_uuid' => $this->venue_seat_uuid,
            'col' => $this->col,
            'row' => $this->row,
            'seat_no' => $this->seat_no,
            'category' => $this->category,
            'color' => $this->color,
            'status' => $this->status,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

            // Relationships
            'ticket' => $this->whenLoaded('ticket', function () {
                return [
                    'uuid' => $this->ticket->uuid,
                    'qr_code' => $this->ticket->qr_code,
                    'attendee_name' => $this->ticket->attendee_name,
                    'status' => $this->ticket->status,
                    'user' => $this->whenLoaded('ticket.user', function () {
                        return [
                            'uuid' => $this->ticket->user->uuid,
                            'name' => $this->ticket->user->first_name . ' ' . $this->ticket->user->last_name,
                        ];
                    }),
                    'event' => $this->whenLoaded('ticket.event', function () {
                        return [
                            'uuid' => $this->ticket->event->uuid,
                            'name' => $this->ticket->event->event_name,
                        ];
                    }),
                ];
            }),
            'venue_seat' => $this->whenLoaded('venueSeat', function () {
                return [
                    'uuid' => $this->venueSeat->uuid,
                    'col' => $this->venueSeat->col,
                    'row' => $this->venueSeat->row,
                    'seat_no' => $this->venueSeat->seat_no,
                    'venue' => $this->whenLoaded('venueSeat.venue', function () {
                        return [
                            'uuid' => $this->venueSeat->venue->uuid,
                            'name' => $this->venueSeat->venue->name,
                        ];
                    }),
                ];
            }),
            'creator' => $this->whenLoaded('creator', function () {
                return [
                    'uuid' => $this->creator->uuid,
                    'name' => $this->creator->first_name . ' ' . $this->creator->last_name,
                ];
            }),
        ];
    }
}
