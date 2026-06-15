<?php

namespace App\Http\Resources;

use App\Support\VenueListingImageResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VenueListingPublicResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $images = VenueListingImageResolver::resolve($this->resource);

        return [
            'uuid' => $this->uuid,
            'slug' => $this->slug,
            'name' => $this->name,
            'location' => $this->location,
            'city' => $this->city,
            'capacity' => $this->capacity_label,
            'area' => $this->area,
            'type' => $this->venue_type,
            'category' => $this->category,
            'price_per_event' => (float) $this->price_per_event,
            'currency' => $this->currency === 'PHP' ? '₱' : $this->currency,
            'rating' => (float) $this->rating,
            'review_count' => $this->review_count,
            'badge' => $this->badge,
            'image_color' => $this->image_color,
            'featured_image_url' => $images['featured_image_url'],
            'gallery_image_urls' => $images['gallery_image_urls'],
            'photo_count' => $images['photo_count'],
            'gallery_colors' => $images['gallery_colors'],
        ];
    }
}
