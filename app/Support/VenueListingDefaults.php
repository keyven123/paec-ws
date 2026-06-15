<?php

namespace App\Support;

use App\Models\VenueListing;

class VenueListingDefaults
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function baseSetups(): array
    {
        return [
            ['id' => 'theater', 'label' => 'Theater', 'capacity' => 500],
            ['id' => 'banquet', 'label' => 'Banquet', 'capacity' => 400],
            ['id' => 'classroom', 'label' => 'Classroom', 'capacity' => 280],
            ['id' => 'ballroom', 'label' => 'Ballroom', 'capacity' => 450],
            ['id' => 'cocktail', 'label' => 'Cocktail', 'capacity' => 500],
            ['id' => 'ushape', 'label' => 'U-shape', 'capacity' => 80],
            ['id' => 'boardroom', 'label' => 'Boardroom', 'capacity' => 40],
            ['id' => 'banquet-stage', 'label' => 'Banquet + stage', 'capacity' => 350],
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function baseAmenities(): array
    {
        return [
            'High-speed WiFi',
            'Central air-conditioning',
            'CCTV security',
            'Restrooms (6)',
            'LED projector + screen',
            'In-house catering',
            'Stage lighting rig',
            'Holding / VIP room',
            'PA / sound system',
            'PWD accessible',
            'Tables & chairs',
            'Backup generator',
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function baseBestFor(): array
    {
        return [
            'Corporate events',
            'Product launches',
            'Gala dinners',
            'Weddings',
            'Conferences',
            'Concerts / shows',
            'Seminars',
        ];
    }

    /**
     * @return array<int, array<string, string>>
     */
    public static function baseSpecs(?string $floorArea = null): array
    {
        return [
            ['label' => 'Floor area', 'value' => $floorArea ?: '800 sqm'],
            ['label' => 'Ceiling height', 'value' => '6.5 m'],
            ['label' => 'Parking slots', 'value' => '120 (basement + valet)'],
            ['label' => 'Power supply', 'value' => '200 kVA · 3-phase'],
            ['label' => 'Load-in access', 'value' => '2 freight elevators · ramp'],
            ['label' => 'Curfew', 'value' => '2:00 AM (extensions available)'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function baseReviews(): array
    {
        return [
            [
                'id' => 'r1',
                'initials' => 'ML',
                'name' => 'Maria L.',
                'category' => 'Corporate',
                'date' => 'May 2026',
                'event' => 'Annual General Meeting',
                'rating' => 5,
                'text' => 'Seamless from inquiry to event day. The venue was exactly as described, staff were attentive, and the AV setup was professional.',
            ],
            [
                'id' => 'r2',
                'initials' => 'RC',
                'name' => 'Ramon C.',
                'category' => 'Celebration',
                'date' => 'Apr 2026',
                'event' => 'Product launch cocktail',
                'rating' => 4,
                'text' => 'Great space for 300 pax in a cocktail setup. Ingress was smooth with 3 entry points. Would rebook.',
            ],
        ];
    }

    /**
     * @return array{0: int|null, 1: int|null}
     */
    public static function parseCapacityBounds(?string $capacityLabel): array
    {
        if (empty($capacityLabel)) {
            return [null, null];
        }

        if (preg_match('/(\d+)\s*[-–]\s*(\d+)/', $capacityLabel, $matches)) {
            return [(int) $matches[1], (int) $matches[2]];
        }

        return [null, null];
    }

    /**
     * Fill missing marketplace detail fields while preserving venue-specific values.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function applyMissing(array $payload, ?VenueListing $existing = null): array
    {
        $area = $payload['area'] ?? $existing?->area;
        $capacityLabel = $payload['capacity_label'] ?? $existing?->capacity_label;

        if (!array_key_exists('setups', $payload) && empty($existing?->setups)) {
            $payload['setups'] = self::baseSetups();
        }

        if (array_key_exists('packages', $payload)) {
            $payload['packages'] = VenueListingPackageHelper::normalizeList($payload['packages']);
        }

        if (!array_key_exists('specs', $payload) && empty($existing?->specs)) {
            $payload['specs'] = self::baseSpecs($area);
        }

        if (!array_key_exists('best_for', $payload) && empty($existing?->best_for)) {
            $payload['best_for'] = self::baseBestFor();
        }

        if (!array_key_exists('amenities', $payload) && empty($existing?->amenities)) {
            $payload['amenities'] = self::baseAmenities();
        }

        if (!array_key_exists('reviews', $payload) && empty($existing?->reviews)) {
            $payload['reviews'] = self::baseReviews();
        }

        if (
            !array_key_exists('capacity_min', $payload)
            && empty($existing?->capacity_min)
            && !array_key_exists('capacity_max', $payload)
            && empty($existing?->capacity_max)
        ) {
            [$min, $max] = self::parseCapacityBounds($capacityLabel);
            if ($min !== null) {
                $payload['capacity_min'] = $min;
            }
            if ($max !== null) {
                $payload['capacity_max'] = $max;
            }
        }

        if (!array_key_exists('min_capacity_note', $payload) && empty($existing?->min_capacity_note)) {
            $payload['min_capacity_note'] = 'guests · intimate setups & meetings';
        }

        if (!array_key_exists('max_capacity_note', $payload) && empty($existing?->max_capacity_note)) {
            $payload['max_capacity_note'] = 'guests · full banquet / standing';
        }

        if (!array_key_exists('responds_in', $payload) && empty($existing?->responds_in)) {
            $payload['responds_in'] = '24 hrs';
        }

        if (!array_key_exists('verified', $payload) && $existing?->verified === null) {
            $payload['verified'] = true;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public static function resolveForDisplay(VenueListing $listing): array
    {
        [$parsedMin, $parsedMax] = self::parseCapacityBounds($listing->capacity_label);

        return [
            'setups' => !empty($listing->setups) ? $listing->setups : self::baseSetups(),
            'specs' => !empty($listing->specs) ? $listing->specs : self::baseSpecs($listing->area),
            'best_for' => !empty($listing->best_for) ? $listing->best_for : self::baseBestFor(),
            'amenities' => !empty($listing->amenities) ? $listing->amenities : self::baseAmenities(),
            'reviews' => !empty($listing->reviews) ? $listing->reviews : self::baseReviews(),
            'min_capacity' => $listing->capacity_min ?? $parsedMin,
            'max_capacity' => $listing->capacity_max ?? $parsedMax,
            'min_capacity_note' => $listing->min_capacity_note ?: 'guests · intimate setups & meetings',
            'max_capacity_note' => $listing->max_capacity_note ?: 'guests · full banquet / standing',
        ];
    }
}
