<?php

namespace App\Support;

use App\Services\Chat\ContactInfoFilter;

class VenueListingPublicDetailMasker
{
    public function __construct(private ContactInfoFilter $contactInfoFilter) {}

    public function mask(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }

        return $this->contactInfoFilter->mask($text);
    }

    /**
     * @param  array<int, array<string, mixed>>  $specs
     * @return array<int, array<string, mixed>>
     */
    public function maskSpecs(array $specs): array
    {
        return array_map(function (array $spec) {
            $masked = $spec;
            if (array_key_exists('label', $spec)) {
                $masked['label'] = $this->mask((string) $spec['label']);
            }
            if (array_key_exists('value', $spec)) {
                $masked['value'] = $this->mask((string) $spec['value']);
            }

            return $masked;
        }, $specs);
    }

    /**
     * @param  array<int, array<string, mixed>>  $packages
     * @return array<int, array<string, mixed>>
     */
    public function maskPackages(array $packages): array
    {
        return array_map(function (array $package) {
            $masked = $package;
            foreach (['label', 'note', 'time_label'] as $field) {
                if (array_key_exists($field, $package)) {
                    $masked[$field] = $this->mask((string) $package[$field]);
                }
            }

            return $masked;
        }, $packages);
    }

    /**
     * @param  array<int, array<string, mixed>>  $reviews
     * @return array<int, array<string, mixed>>
     */
    public function maskReviews(array $reviews): array
    {
        return array_map(function (array $review) {
            $masked = $review;
            foreach (['name', 'text', 'event', 'category'] as $field) {
                if (array_key_exists($field, $review)) {
                    $masked[$field] = $this->mask((string) $review[$field]);
                }
            }

            return $masked;
        }, $reviews);
    }

    /**
     * @param  array<int, string>  $items
     * @return array<int, string>
     */
    public function maskStringList(array $items): array
    {
        return array_map(fn (string $item) => $this->mask($item) ?? '', $items);
    }
}
