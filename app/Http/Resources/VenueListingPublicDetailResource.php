<?php

namespace App\Http\Resources;

use App\Support\VenueListingDefaults;
use App\Support\VenueListingImageResolver;
use App\Support\VenueListingPackageHelper;
use App\Support\VenueListingPublicDetailMasker;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VenueListingPublicDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $listing = (new VenueListingPublicResource($this->resource))->toArray($request);
        $details = VenueListingDefaults::resolveForDisplay($this->resource);
        $images = VenueListingImageResolver::resolve($this->resource);
        $masker = app(VenueListingPublicDetailMasker::class);
        $packages = $this->resolvePackages();

        return [
            'listing' => $listing,
            'slug' => $this->slug,
            'address' => $masker->mask($this->address ?: trim(($this->location ?? '') . ', ' . $this->city, ', ')),
            'region' => $this->region,
            'area_label' => $this->city,
            'verified' => $this->verified,
            'responds_in' => $this->responds_in ?: '24 hrs',
            'about' => $masker->mask($this->description),
            'photo_count' => $images['photo_count'],
            'gallery_colors' => $images['gallery_colors'],
            'featured_image_url' => $images['featured_image_url'],
            'gallery_image_urls' => $images['gallery_image_urls'],
            'packages' => $masker->maskPackages($packages),
            'default_package_id' => $this->default_package_id ?: 'full-day',
            'min_capacity' => $details['min_capacity'],
            'min_capacity_note' => $masker->mask($details['min_capacity_note']),
            'max_capacity' => $details['max_capacity'],
            'max_capacity_note' => $masker->mask($details['max_capacity_note']),
            'setups' => $details['setups'],
            'specs' => $masker->maskSpecs($details['specs']),
            'best_for' => $masker->maskStringList($details['best_for']),
            'amenities' => $masker->maskStringList($details['amenities']),
            'reviews' => $masker->maskReviews($details['reviews']),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function resolvePackages(): array
    {
        $packages = $this->packages ?? [];

        if (! empty($packages)) {
            return VenueListingPackageHelper::normalizeList($packages);
        }

        return VenueListingPackageHelper::basePackages($this->price_per_event);
    }
}
