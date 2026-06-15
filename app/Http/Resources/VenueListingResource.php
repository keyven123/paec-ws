<?php

namespace App\Http\Resources;

use App\Support\VenueListingImageResolver;
use App\Support\VenueListingPackageHelper;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VenueListingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $images = VenueListingImageResolver::resolve($this->resource);
        $featured = $this->relationLoaded('featuredImage') ? $this->featuredImage : null;
        $gallery = $this->relationLoaded('gallery') ? $this->gallery : collect();

        return [
            'uuid' => $this->uuid,
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'address' => $this->address,
            'location' => $this->location,
            'city' => $this->city,
            'region' => $this->region,
            'area' => $this->area,
            'capacity' => $this->capacity_label,
            'capacity_label' => $this->capacity_label,
            'capacity_min' => $this->capacity_min,
            'capacity_max' => $this->capacity_max,
            'type' => $this->venue_type,
            'venue_type' => $this->venue_type,
            'category' => $this->category,
            'price_per_event' => (float) $this->price_per_event,
            'currency' => $this->currency,
            'status' => $this->status,
            'featured' => $this->is_featured,
            'is_featured' => $this->is_featured,
            'badge' => $this->badge,
            'rating' => (float) $this->rating,
            'review_count' => $this->review_count,
            'inquiries_count' => $this->inquiries_count,
            'bookings_count' => $this->bookings_count,
            'image_color' => $this->image_color,
            'featured_image_url' => $images['featured_image_url'],
            'featured_upload_uuid' => $featured?->uuid,
            'gallery_image_urls' => $images['gallery_image_urls'],
            'gallery_upload_uuids' => $gallery->pluck('uuid')->values()->all(),
            'verified' => $this->verified,
            'responds_in' => $this->responds_in,
            'photo_count' => $images['photo_count'],
            'gallery_colors' => $images['gallery_colors'],
            'packages' => VenueListingPackageHelper::normalizeList(
                ! empty($this->packages) ? $this->packages : VenueListingPackageHelper::basePackages($this->price_per_event),
            ),
            'default_package_id' => $this->default_package_id ?: 'full-day',
            'min_capacity_note' => $this->min_capacity_note,
            'max_capacity_note' => $this->max_capacity_note,
            'setups' => $this->setups,
            'specs' => $this->specs,
            'best_for' => $this->best_for,
            'amenities' => $this->amenities,
            'reviews' => $this->reviews,
            'organization_uuid' => $this->organization_uuid,
            'organization_name' => $this->whenLoaded('organization', fn () => $this->organization?->name),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
