<?php

namespace App\Support;

use App\Models\VenueListing;
use Illuminate\Support\Collection;

class VenueListingImageResolver
{
    /**
     * @return array{
     *     featured_image_url: string|null,
     *     gallery_image_urls: list<string>,
     *     gallery_colors: list<string>,
     *     photo_count: int
     * }
     */
    public static function resolve(VenueListing $listing): array
    {
        $featured = $listing->relationLoaded('featuredImage') ? $listing->featuredImage : null;
        $gallery  = $listing->relationLoaded('gallery')
            ? $listing->gallery
            : new Collection();

        $featuredUrl  = $featured?->url;
        $galleryUrls  = $gallery->pluck('url')->filter()->values()->all();
        $galleryColors = $gallery->pluck('dominant_color')->filter()->values()->all();

        if (empty($galleryColors) && !empty($listing->image_color)) {
            $galleryColors = [$listing->image_color];
        }

        return [
            'featured_image_url' => $featuredUrl,
            'gallery_image_urls' => $galleryUrls,
            'gallery_colors'     => $galleryColors,
            'photo_count'        => ($featuredUrl ? 1 : 0) + count($galleryUrls),
        ];
    }
}
