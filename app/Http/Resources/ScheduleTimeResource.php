<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ScheduleTimeResource extends JsonResource
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
            'schedule_uuid' => $this->schedule_uuid,
            'time_start' => $this->time_start,
            'time_end' => $this->time_end,
            'status' => $this->status,

            'event_tickets' => $this->whenLoaded('eventTickets', function () {
                return EventTicketResource::collection($this->eventTickets);
            }),
        ];
    }
}
