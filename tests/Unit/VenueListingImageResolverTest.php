<?php

namespace Tests\Unit;

use App\Models\Upload;
use App\Models\VenueListing;
use App\Support\VenueListingImageResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VenueListingImageResolverTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_resolves_featured_and_gallery_images_from_uploads(): void
    {
        $listing = VenueListing::factory()->create([
            'slug' => 'resolver-test-venue',
            'image_color' => '#112233',
        ]);

        Upload::create([
            'uploadable_type' => VenueListing::class,
            'uploadable_uuid' => $listing->uuid,
            'collection'      => 'featured',
            'type'            => 'image',
            'path'            => 'https://example.com/featured.jpg',
            'dominant_color'  => '#112233',
            'order_number'    => 0,
        ]);

        Upload::create([
            'uploadable_type' => VenueListing::class,
            'uploadable_uuid' => $listing->uuid,
            'collection'      => 'gallery',
            'type'            => 'image',
            'path'            => 'https://example.com/gallery-1.jpg',
            'dominant_color'  => '#223344',
            'order_number'    => 0,
        ]);

        Upload::create([
            'uploadable_type' => VenueListing::class,
            'uploadable_uuid' => $listing->uuid,
            'collection'      => 'gallery',
            'type'            => 'image',
            'path'            => 'https://example.com/gallery-2.jpg',
            'dominant_color'  => '#334455',
            'order_number'    => 1,
        ]);

        $listing->load(['featuredImage', 'gallery']);
        $resolved = VenueListingImageResolver::resolve($listing);

        $this->assertSame('https://example.com/featured.jpg', $resolved['featured_image_url']);
        $this->assertSame([
            'https://example.com/gallery-1.jpg',
            'https://example.com/gallery-2.jpg',
        ], $resolved['gallery_image_urls']);
        $this->assertSame(['#223344', '#334455'], $resolved['gallery_colors']);
        $this->assertSame(3, $resolved['photo_count']);
    }

    #[Test]
    public function it_falls_back_to_image_color_when_gallery_has_no_dominant_colors(): void
    {
        $listing = VenueListing::factory()->create([
            'image_color' => '#abcdef',
        ]);

        $listing->load(['featuredImage', 'gallery']);
        $resolved = VenueListingImageResolver::resolve($listing);

        $this->assertNull($resolved['featured_image_url']);
        $this->assertSame([], $resolved['gallery_image_urls']);
        $this->assertSame(['#abcdef'], $resolved['gallery_colors']);
        $this->assertSame(0, $resolved['photo_count']);
    }

    #[Test]
    public function it_returns_empty_payload_when_upload_relations_are_not_loaded(): void
    {
        $listing = VenueListing::factory()->create([
            'image_color' => '#123456',
        ]);

        $resolved = VenueListingImageResolver::resolve($listing);

        $this->assertNull($resolved['featured_image_url']);
        $this->assertSame([], $resolved['gallery_image_urls']);
        $this->assertSame(['#123456'], $resolved['gallery_colors']);
        $this->assertSame(0, $resolved['photo_count']);
    }
}
