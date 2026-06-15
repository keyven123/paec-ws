<?php

namespace Tests\Feature;

use App\Models\VenueListing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesVenueListingFixtures;
use Tests\TestCase;

/**
 * Covers the generic, polymorphic blocked-dates endpoints using venue listings
 * as the blockable resource. Event-specific coverage lives in
 * AdminEventsRoutesTest.
 */
class BlockedDateRoutesTest extends TestCase
{
    use CreatesVenueListingFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpVenueListingAdmin();
    }

    #[Test]
    public function it_lists_blocked_dates_for_a_venue_listing(): void
    {
        $listing = $this->createVenueListing();

        $this->withHeaders($this->withVenueAdminHeaders())
            ->getJson('/api/v1/venue-listings/' . $listing->uuid . '/blocked-dates')
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    #[Test]
    public function it_creates_a_blocked_date_for_a_venue_listing(): void
    {
        $listing = $this->createVenueListing();

        $response = $this->withHeaders($this->withVenueAdminHeaders())
            ->postJson('/api/v1/venue-listings/' . $listing->uuid . '/blocked-dates', [
                'blocked_date' => '2031-12-25',
                'reason' => 'Holiday closure',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.blockable_type', VenueListing::class)
            ->assertJsonPath('data.blockable_uuid', $listing->uuid)
            ->assertJsonPath('data.blocked_date', '2031-12-25')
            ->assertJsonPath('data.reason', 'Holiday closure');

        $this->assertDatabaseHas('blocked_dates', [
            'blockable_type' => VenueListing::class,
            'blockable_uuid' => $listing->uuid,
            'reason' => 'Holiday closure',
        ]);
    }

    #[Test]
    public function it_rejects_a_duplicate_blocked_date(): void
    {
        $listing = $this->createVenueListing();
        $payload = ['blocked_date' => '2031-12-26'];

        $this->withHeaders($this->withVenueAdminHeaders())
            ->postJson('/api/v1/venue-listings/' . $listing->uuid . '/blocked-dates', $payload)
            ->assertStatus(201);

        $this->withHeaders($this->withVenueAdminHeaders())
            ->postJson('/api/v1/venue-listings/' . $listing->uuid . '/blocked-dates', $payload)
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This date is already blocked.');
    }

    #[Test]
    public function it_scopes_blocked_dates_to_their_own_blockable(): void
    {
        $listingA = $this->createVenueListing();
        $listingB = $this->createVenueListing();

        $this->withHeaders($this->withVenueAdminHeaders())
            ->postJson('/api/v1/venue-listings/' . $listingA->uuid . '/blocked-dates', [
                'blocked_date' => '2031-12-27',
            ])->assertStatus(201);

        $this->withHeaders($this->withVenueAdminHeaders())
            ->getJson('/api/v1/venue-listings/' . $listingB->uuid . '/blocked-dates')
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    #[Test]
    public function it_creates_and_deletes_a_blocked_date_round_trip(): void
    {
        $listing = $this->createVenueListing();

        $create = $this->withHeaders($this->withVenueAdminHeaders())
            ->postJson('/api/v1/venue-listings/' . $listing->uuid . '/blocked-dates', [
                'blocked_date' => '2031-12-28',
                'reason' => 'Private event',
            ]);

        $create->assertStatus(201);
        $blockedUuid = $create->json('data.uuid');
        $this->assertNotEmpty($blockedUuid);

        $this->withHeaders($this->withVenueAdminHeaders())
            ->deleteJson('/api/v1/venue-listings/' . $listing->uuid . '/blocked-dates/' . $blockedUuid)
            ->assertStatus(204);

        $this->assertSoftDeleted('blocked_dates', [
            'uuid' => $blockedUuid,
        ]);
    }

    #[Test]
    public function it_restores_a_soft_deleted_blocked_date_when_re_added(): void
    {
        $listing = $this->createVenueListing();

        $create = $this->withHeaders($this->withVenueAdminHeaders())
            ->postJson('/api/v1/venue-listings/' . $listing->uuid . '/blocked-dates', [
                'blocked_date' => '2031-12-29',
            ]);
        $blockedUuid = $create->json('data.uuid');

        $this->withHeaders($this->withVenueAdminHeaders())
            ->deleteJson('/api/v1/venue-listings/' . $listing->uuid . '/blocked-dates/' . $blockedUuid)
            ->assertStatus(204);

        $this->withHeaders($this->withVenueAdminHeaders())
            ->postJson('/api/v1/venue-listings/' . $listing->uuid . '/blocked-dates', [
                'blocked_date' => '2031-12-29',
                'reason' => 'Re-blocked',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.uuid', $blockedUuid)
            ->assertJsonPath('data.reason', 'Re-blocked');
    }

    #[Test]
    public function it_returns_404_for_an_unknown_venue_listing(): void
    {
        $this->withHeaders($this->withVenueAdminHeaders())
            ->getJson('/api/v1/venue-listings/550e8400-e29b-41d4-a716-446655440000/blocked-dates')
            ->assertStatus(404);
    }

    #[Test]
    public function it_requires_authentication(): void
    {
        $listing = $this->createVenueListing();
        auth('admin')->logout();

        $this->getJson('/api/v1/venue-listings/' . $listing->uuid . '/blocked-dates')
            ->assertUnauthorized();
    }

    #[Test]
    public function public_endpoint_lists_blocked_dates_by_slug(): void
    {
        $listing = $this->createVenueListing(['status' => VenueListing::STATUSES['PUBLISHED']]);

        $this->withHeaders($this->withVenueAdminHeaders())
            ->postJson('/api/v1/venue-listings/' . $listing->uuid . '/blocked-dates', [
                'blocked_date' => '2031-12-30',
                'reason' => 'Year-end closure',
            ])->assertStatus(201);

        $this->getJson('/api/v1/public/venue-listings/' . $listing->slug . '/blocked-dates')
            ->assertOk()
            ->assertJsonFragment([
                'blocked_date' => '2031-12-30',
                'reason' => 'Year-end closure',
            ]);
    }

    #[Test]
    public function public_endpoint_returns_404_for_unknown_slug(): void
    {
        $this->getJson('/api/v1/public/venue-listings/non-existent-slug/blocked-dates')
            ->assertStatus(404);
    }
}
