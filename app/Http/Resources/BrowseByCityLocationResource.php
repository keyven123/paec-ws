<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BrowseByCityLocationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $imageUrl = null;
        $priceStart = null;

        if ($this->relationLoaded('event') && $this->event) {
            $event = $this->event;

            if ($event->relationLoaded('featuredImage') && $event->featuredImage) {
                $imageUrl = $event->featuredImage->url;
            } elseif ($event->relationLoaded('portraitImage') && $event->portraitImage) {
                $imageUrl = $event->portraitImage->url;
            } elseif ($event->relationLoaded('logo') && $event->logo) {
                $imageUrl = $event->logo->url;
            }

            if ($event->relationLoaded('eventTickets')) {
                $priceStart = $event->eventTickets->min('price');
            }
        }

        return [
            'uuid' => $this->uuid,
            'city' => $this->city,
            'name' => $this->name,
            'address' => $this->address,
            'label' => $this->displayLabel(),
            'event_uuid' => $this->event_uuid,
            'event_slug' => $this->whenLoaded('event', fn () => $this->event->slug),
            'event_name' => $this->whenLoaded('event', fn () => $this->event->event_name),
            'price_start' => $priceStart,
            'image' => $imageUrl ? [
                'url' => $imageUrl,
            ] : null,
        ];
    }
}
