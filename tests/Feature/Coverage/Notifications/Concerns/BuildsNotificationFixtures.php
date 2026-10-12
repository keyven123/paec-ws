<?php

namespace Tests\Feature\Coverage\Notifications\Concerns;

use App\Models\Upload;
use App\Models\VenueInquiry;
use App\Models\VenueListing;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

trait BuildsNotificationFixtures
{
    protected const FEATURED_IMAGE_URL = 'https://cdn.example.test/venues/featured.jpg';

    protected const FRONTEND_URL = 'https://frontend.example.test';

    /**
     * Serve the given responses (or throw the given exceptions) in order for every outbound HTTP call.
     *
     * @param  list<mixed>  $responses
     */
    protected function fakeImageResponses(array $responses): void
    {
        $queue = array_values($responses);

        Http::preventStrayRequests();
        Http::fake(function () use (&$queue) {
            $next = array_shift($queue);
            if ($next instanceof \Throwable) {
                throw $next;
            }

            return $next;
        });
    }

    /**
     * Remote responses paired with the embed payload the notification is expected to build from them.
     *
     * @return array<string, array{0: mixed, 1: array{data: string, mime: string, cidName: string}|null}>
     */
    protected function imageEmbedScenarios(string $cidBase, bool $svgHasOwnExtension): array
    {
        $embed = fn (string $data, string $mime, string $ext) => [
            'data' => $data,
            'mime' => $mime,
            'cidName' => $cidBase . '.' . $ext,
        ];

        return [
            'server error' => [Http::response('missing', 404), null],
            'empty body' => [Http::response('', 200, ['Content-Type' => 'image/png']), null],
            'non-image content type' => [
                Http::response('HTML-BYTES', 200, ['Content-Type' => 'text/html']),
                $embed('HTML-BYTES', 'image/jpeg', 'jpg'),
            ],
            'png with parameters' => [
                Http::response('PNG-BYTES', 200, ['Content-Type' => 'image/png; charset=binary']),
                $embed('PNG-BYTES', 'image/png', 'png'),
            ],
            'gif' => [
                Http::response('GIF-BYTES', 200, ['Content-Type' => 'image/gif']),
                $embed('GIF-BYTES', 'image/gif', 'gif'),
            ],
            'webp' => [
                Http::response('WEBP-BYTES', 200, ['Content-Type' => 'image/webp']),
                $embed('WEBP-BYTES', 'image/webp', 'webp'),
            ],
            'svg' => [
                Http::response('SVG-BYTES', 200, ['Content-Type' => 'image/svg+xml']),
                $embed('SVG-BYTES', 'image/svg+xml', $svgHasOwnExtension ? 'svg' : 'jpg'),
            ],
            'jpeg' => [
                Http::response('JPEG-BYTES', 200, ['Content-Type' => 'image/jpeg']),
                $embed('JPEG-BYTES', 'image/jpeg', 'jpg'),
            ],
            'connection failure' => [new ConnectionException('Connection timed out'), null],
        ];
    }

    /**
     * @param  array<string, mixed>  $inquiryOverrides
     * @param  array<string, mixed>  $listingOverrides
     */
    protected function createVenueInquiry(
        array $inquiryOverrides = [],
        array $listingOverrides = [],
        bool $withFeaturedImage = true,
    ): VenueInquiry {
        $listing = VenueListing::factory()->published()->create(array_merge([
            'name' => 'Skyline Function Hall',
            'slug' => 'skyline-function-hall',
            'city' => 'Makati City',
            'location' => 'Ayala Avenue',
            'venue_type' => 'Function hall',
        ], $listingOverrides));

        if ($withFeaturedImage) {
            Upload::create([
                'uploadable_type' => VenueListing::class,
                'uploadable_uuid' => $listing->uuid,
                'collection' => 'featured',
                'type' => 'image',
                'mime_type' => 'image/jpeg',
                'extension' => 'jpg',
                'disk' => 'public',
                'path' => self::FEATURED_IMAGE_URL,
                'order_number' => 0,
            ]);
        }

        return VenueInquiry::factory()->create(array_merge([
            'venue_listing_uuid' => $listing->uuid,
            'full_name' => 'Maria Santos',
            'email' => 'maria.santos@example.test',
            'phone' => '09171234567',
            'event_type' => 'Wedding reception',
            'event_date' => '2026-12-12',
            'guest_count' => 150,
            'site_visit' => VenueInquiry::SITE_VISIT_YES,
            'message' => 'Looking for an evening slot.',
            'status' => VenueInquiry::STATUSES['IN_DISCUSSION'],
        ], $inquiryOverrides));
    }

    /**
     * Simulate a listing that was removed out from under an inquiry. Listings are soft-deleted,
     * so the inquiry keeps its reference while the relation resolves to null.
     */
    protected function detachVenueListing(VenueInquiry $inquiry): void
    {
        VenueListing::query()->whereKey($inquiry->venue_listing_uuid)->firstOrFail()->delete();
    }

    protected function inquiryReference(VenueInquiry $inquiry): string
    {
        return strtoupper(substr(str_replace('-', '', $inquiry->uuid), 0, 8));
    }
}
