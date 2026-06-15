<?php

namespace Tests\Feature;

use App\Constants\GeneralConstants;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Models\VenueInquiry;
use App\Models\VenueListing;
use App\Notifications\VenueVisitScheduledNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesVenueListingFixtures;
use Tests\TestCase;

class VenueListingControllerTest extends TestCase
{
    use CreatesVenueListingFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpVenueListingAdmin();
    }

    #[Test]
    public function it_can_list_venue_listings(): void
    {
        $listing = $this->createVenueListing(['name' => 'Listed Venue Hall']);

        $response = $this->withHeaders($this->withVenueAdminHeaders())
            ->getJson('/api/v1/venue-listings');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'uuid',
                        'slug',
                        'name',
                        'city',
                        'status',
                        'featured_image_url',
                        'gallery_image_urls',
                        'photo_count',
                    ],
                ],
            ])
            ->assertJsonFragment([
                'uuid' => $listing->uuid,
                'name' => 'Listed Venue Hall',
            ]);
    }

    #[Test]
    public function it_can_get_venue_listing_stats(): void
    {
        $this->createVenueListing(['status' => VenueListing::STATUSES['PUBLISHED']]);
        $this->createVenueListing(['status' => VenueListing::STATUSES['PUBLISHED']]);
        $this->createVenueListing(['status' => VenueListing::STATUSES['PENDING']]);

        $response = $this->withHeaders($this->withVenueAdminHeaders())
            ->getJson('/api/v1/venue-listings/stats');

        $response->assertOk()
            ->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.published', 2)
            ->assertJsonPath('data.pending', 1);
    }

    #[Test]
    public function it_can_create_a_venue_listing(): void
    {
        $organization = Organization::factory()->create();

        $payload = [
            'organization_uuid' => $organization->uuid,
            'name' => 'New Grand Hall',
            'city' => 'Taguig City',
            'venue_type' => 'Function hall',
            'price_per_event' => 55000,
            'status' => VenueListing::STATUSES['DRAFT'],
        ];

        $response = $this->withHeaders($this->withVenueAdminHeaders())
            ->postJson('/api/v1/venue-listings', $payload);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'New Grand Hall')
            ->assertJsonPath('data.city', 'Taguig City')
            ->assertJsonPath('data.status', VenueListing::STATUSES['DRAFT'])
            ->assertJsonPath('data.organization_uuid', $organization->uuid);

        $this->assertDatabaseHas('venue_listings', [
            'organization_uuid' => $organization->uuid,
            'name' => 'New Grand Hall',
            'city' => 'Taguig City',
            'venue_type' => 'Function hall',
        ]);
    }

    #[Test]
    public function it_can_show_a_venue_listing(): void
    {
        $listing = $this->createVenueListing(['name' => 'Showcase Venue']);
        $this->attachListingImages($listing);

        $response = $this->withHeaders($this->withVenueAdminHeaders())
            ->getJson('/api/v1/venue-listings/' . $listing->uuid);

        $response->assertOk()
            ->assertJsonPath('data.uuid', $listing->uuid)
            ->assertJsonPath('data.name', 'Showcase Venue')
            ->assertJsonPath('data.featured_image_url', "https://example.com/{$listing->slug}-featured.jpg")
            ->assertJsonCount(3, 'data.gallery_image_urls')
            ->assertJsonPath('data.photo_count', 4)
            ->assertJsonStructure([
                'data' => [
                    'inquiry_status_counts',
                    'stats' => ['inquiries_count', 'bookings_count', 'rating', 'review_count'],
                ],
            ]);
    }

    #[Test]
    public function it_counts_fully_paid_inquiries_as_bookings_on_venue_dashboard(): void
    {
        $listing = $this->createVenueListing([
            'bookings_count' => 99,
        ]);

        VenueInquiry::factory()->count(2)->create([
            'venue_listing_uuid' => $listing->uuid,
            'status' => VenueInquiry::STATUSES['FULLY_PAID'],
        ]);
        VenueInquiry::factory()->create([
            'venue_listing_uuid' => $listing->uuid,
            'status' => VenueInquiry::STATUSES['NEW'],
        ]);

        $response = $this->withHeaders($this->withVenueAdminHeaders())
            ->getJson('/api/v1/venue-listings/' . $listing->uuid);

        $response->assertOk()
            ->assertJsonPath('data.stats.bookings_count', 2);
    }

    #[Test]
    public function it_paginates_venue_inquiries_and_returns_status_counts(): void
    {
        $listing = $this->createVenueListing();

        VenueInquiry::factory()->count(12)->create([
            'venue_listing_uuid' => $listing->uuid,
            'status' => VenueInquiry::STATUSES['NEW'],
        ]);
        VenueInquiry::factory()->count(3)->create([
            'venue_listing_uuid' => $listing->uuid,
            'status' => VenueInquiry::STATUSES['FULLY_PAID'],
        ]);

        $response = $this->withHeaders($this->withVenueAdminHeaders())
            ->getJson('/api/v1/venue-listings/' . $listing->uuid . '/inquiries?per_page=10&page=1');

        $response->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.total', 15)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('status_counts.all', 15)
            ->assertJsonPath('status_counts.new', 12)
            ->assertJsonPath('status_counts.fully_paid', 3);
    }

    #[Test]
    public function it_hides_customer_contact_details_for_open_and_cancelled_merchant_inquiries(): void
    {
        $listing = $this->createVenueListing();

        $openInquiry = VenueInquiry::factory()->create([
            'venue_listing_uuid' => $listing->uuid,
            'email' => 'hidden-open@test.com',
            'phone' => '09170000001',
            'status' => VenueInquiry::STATUSES['PROPOSAL_SENT'],
        ]);

        $cancelledInquiry = VenueInquiry::factory()->create([
            'venue_listing_uuid' => $listing->uuid,
            'email' => 'hidden-cancelled@test.com',
            'phone' => '09170000002',
            'status' => VenueInquiry::STATUSES['CANCELLED'],
        ]);

        $revealedInquiry = VenueInquiry::factory()->create([
            'venue_listing_uuid' => $listing->uuid,
            'email' => 'visible-paid@test.com',
            'phone' => '09170000003',
            'status' => VenueInquiry::STATUSES['DEPOSIT_PAID'],
        ]);

        $response = $this->withHeaders($this->withVenueAdminHeaders())
            ->getJson('/api/v1/venue-listings/' . $listing->uuid . '/inquiries?per_page=50');

        $response->assertOk();

        $records = collect($response->json('data'))->keyBy('uuid');

        $this->assertNull($records[$openInquiry->uuid]['email']);
        $this->assertNull($records[$openInquiry->uuid]['phone']);
        $this->assertNull($records[$cancelledInquiry->uuid]['email']);
        $this->assertNull($records[$cancelledInquiry->uuid]['phone']);
        $this->assertSame('visible-paid@test.com', $records[$revealedInquiry->uuid]['email']);
        $this->assertSame('09170000003', $records[$revealedInquiry->uuid]['phone']);
    }

    #[Test]
    public function it_can_update_a_venue_listing(): void
    {
        $listing = $this->createVenueListing([
            'name' => 'Before Update',
            'status' => VenueListing::STATUSES['DRAFT'],
        ]);
        $newOrganization = Organization::factory()->create();

        $response = $this->withHeaders($this->withVenueAdminHeaders())
            ->putJson('/api/v1/venue-listings/' . $listing->uuid, [
                'name' => 'After Update',
                'organization_uuid' => $newOrganization->uuid,
                'status' => VenueListing::STATUSES['PUBLISHED'],
                'badge' => 'Featured',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'After Update')
            ->assertJsonPath('data.organization_uuid', $newOrganization->uuid)
            ->assertJsonPath('data.status', VenueListing::STATUSES['PUBLISHED'])
            ->assertJsonPath('data.badge', 'Featured');

        $this->assertDatabaseHas('venue_listings', [
            'uuid' => $listing->uuid,
            'organization_uuid' => $newOrganization->uuid,
            'name' => 'After Update',
            'status' => VenueListing::STATUSES['PUBLISHED'],
        ]);
    }

    #[Test]
    public function it_can_delete_a_venue_listing(): void
    {
        $listing = $this->createVenueListing();

        $response = $this->withHeaders($this->withVenueAdminHeaders())
            ->deleteJson('/api/v1/venue-listings/' . $listing->uuid);

        $response->assertNoContent();

        $this->assertSoftDeleted('venue_listings', [
            'uuid' => $listing->uuid,
        ]);
    }

    #[Test]
    public function it_can_update_a_venue_inquiry(): void
    {
        Notification::fake();

        $customerRole = Role::create([
            'name' => 'Customer',
            'code' => GeneralConstants::ROLES['CUSTOMER']['name'],
        ]);

        $listing = $this->createVenueListing(['status' => VenueListing::STATUSES['PUBLISHED']]);
        $user = User::factory()->create(['role_uuid' => $customerRole->uuid]);
        $inquiry = VenueInquiry::factory()->create([
            'venue_listing_uuid' => $listing->uuid,
            'user_uuid' => $user->uuid,
            'email' => $user->email,
            'site_visit' => VenueInquiry::SITE_VISIT_YES,
        ]);

        $visitDate = now()->addDays(3)->toDateString();

        $response = $this->withHeaders($this->withVenueAdminHeaders())
            ->patchJson('/api/v1/venue-listings/inquiries/' . $inquiry->uuid, [
                'visit_scheduled_date' => $visitDate,
                'visit_scheduled_time' => '14:00',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.status', VenueInquiry::STATUSES['SITE_VISIT_SCHEDULED']);

        $this->assertDatabaseHas('venue_inquiries', [
            'uuid' => $inquiry->uuid,
            'status' => VenueInquiry::STATUSES['SITE_VISIT_SCHEDULED'],
        ]);

        Notification::assertSentTo(
            $user,
            VenueVisitScheduledNotification::class,
            fn (VenueVisitScheduledNotification $notification) => $notification->inquiryUuid === $inquiry->uuid,
        );

        $this->assertDatabaseHas('platform_notifications', [
            'notifiable_type' => User::class,
            'notifiable_uuid' => $user->uuid,
            'type' => 'visit_scheduled',
            'title' => 'Site Visit Scheduled',
        ]);
    }

    #[Test]
    public function it_sends_visit_scheduled_email_to_guest_without_account(): void
    {
        Notification::fake();

        $listing = $this->createVenueListing(['status' => VenueListing::STATUSES['PUBLISHED']]);
        $inquiry = VenueInquiry::factory()->create([
            'venue_listing_uuid' => $listing->uuid,
            'user_uuid' => null,
            'email' => 'guest@example.com',
            'site_visit' => VenueInquiry::SITE_VISIT_YES,
        ]);

        $response = $this->withHeaders($this->withVenueAdminHeaders())
            ->patchJson('/api/v1/venue-listings/inquiries/' . $inquiry->uuid, [
                'visit_scheduled_date' => now()->addDays(5)->toDateString(),
                'visit_scheduled_time' => '10:30',
            ]);

        $response->assertOk();

        Notification::assertSentOnDemand(
            VenueVisitScheduledNotification::class,
            fn (VenueVisitScheduledNotification $notification, array $channels, object $notifiable) => ($notifiable->routes['mail'] ?? null) === 'guest@example.com'
                && $notification->inquiryUuid === $inquiry->uuid,
        );

        $this->assertDatabaseMissing('platform_notifications', [
            'type' => 'visit_scheduled',
        ]);
    }

    #[Test]
    public function it_returns_404_for_non_existent_venue_listing(): void
    {
        $response = $this->withHeaders($this->withVenueAdminHeaders())
            ->getJson('/api/v1/venue-listings/550e8400-e29b-41d4-a716-446655440000');

        $response->assertNotFound()
            ->assertJsonPath('message', 'Venue listing not found');
    }

    #[Test]
    public function it_validates_required_fields_on_create(): void
    {
        $response = $this->withHeaders($this->withVenueAdminHeaders())
            ->postJson('/api/v1/venue-listings', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'city', 'venue_type']);
    }

    #[Test]
    public function it_requires_organization_uuid_for_platform_admin_on_create(): void
    {
        $response = $this->withHeaders($this->withVenueAdminHeaders())
            ->postJson('/api/v1/venue-listings', [
                'name' => 'Platform Admin Hall',
                'city' => 'Taguig City',
                'venue_type' => 'Function hall',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['organization_uuid']);
    }

    #[Test]
    public function it_can_create_venue_listing_as_merchant_without_organization_uuid(): void
    {
        [$merchantHeaders, $organization] = $this->createVenueListingMerchantAuth();

        $payload = [
            'name' => 'Merchant Grand Hall',
            'city' => 'Taguig City',
            'venue_type' => 'Function hall',
            'price_per_event' => 45000,
            'status' => VenueListing::STATUSES['DRAFT'],
        ];

        $response = $this->withHeaders($merchantHeaders)
            ->postJson('/api/v1/venue-listings', $payload);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Merchant Grand Hall')
            ->assertJsonPath('data.organization_uuid', $organization->uuid);

        $this->assertDatabaseHas('venue_listings', [
            'organization_uuid' => $organization->uuid,
            'name' => 'Merchant Grand Hall',
            'city' => 'Taguig City',
        ]);
    }

    #[Test]
    public function it_validates_organization_uuid_exists_on_create(): void
    {
        $response = $this->withHeaders($this->withVenueAdminHeaders())
            ->postJson('/api/v1/venue-listings', [
                'organization_uuid' => '550e8400-e29b-41d4-a716-446655440000',
                'name' => 'Invalid Org Venue',
                'city' => 'Makati City',
                'venue_type' => 'Function hall',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['organization_uuid']);
    }

    #[Test]
    public function it_can_store_and_update_custom_venue_packages(): void
    {
        $organization = Organization::factory()->create();

        $packages = [
            [
                'id' => 'half-day-morning',
                'label' => 'Half day (Morning)',
                'priceFrom' => 25000,
                'note' => 'Morning block · final rate varies by date & setup',
                'start_time' => '06:00',
                'end_time' => '15:00',
                'crosses_midnight' => false,
                'sort_order' => 0,
            ],
            [
                'id' => 'half-day-afternoon',
                'label' => 'Half day (Afternoon)',
                'priceFrom' => 25000,
                'note' => 'Afternoon / evening block · final rate varies by date & setup',
                'start_time' => '17:00',
                'end_time' => '01:00',
                'crosses_midnight' => true,
                'sort_order' => 1,
            ],
        ];

        $createResponse = $this->withHeaders($this->withVenueAdminHeaders())
            ->postJson('/api/v1/venue-listings', [
                'organization_uuid' => $organization->uuid,
                'name' => 'Packaged Venue Hall',
                'city' => 'Makati City',
                'venue_type' => 'Function hall',
                'packages' => $packages,
                'default_package_id' => 'half-day-morning',
            ]);

        $createResponse->assertCreated()
            ->assertJsonCount(2, 'data.packages')
            ->assertJsonPath('data.packages.0.label', 'Half day (Morning)')
            ->assertJsonPath('data.packages.0.time_label', '6:00 AM – 3:00 PM')
            ->assertJsonPath('data.packages.1.crosses_midnight', true)
            ->assertJsonPath('data.default_package_id', 'half-day-morning');

        $uuid = $createResponse->json('data.uuid');

        $updateResponse = $this->withHeaders($this->withVenueAdminHeaders())
            ->putJson('/api/v1/venue-listings/' . $uuid, [
                'packages' => [
                    [
                        'id' => 'full-day',
                        'label' => 'Full-day (8 hrs)',
                        'priceFrom' => 45000,
                        'note' => 'Full-day package · final rate varies by date & setup',
                        'start_time' => '07:00',
                        'end_time' => '21:00',
                    ],
                ],
                'default_package_id' => 'full-day',
            ]);

        $updateResponse->assertOk()
            ->assertJsonCount(1, 'data.packages')
            ->assertJsonPath('data.packages.0.label', 'Full-day (8 hrs)')
            ->assertJsonPath('data.packages.0.time_label', '7:00 AM – 9:00 PM')
            ->assertJsonPath('data.default_package_id', 'full-day');
    }

    #[Test]
    public function it_applies_default_packages_when_none_are_provided(): void
    {
        $organization = Organization::factory()->create();

        $response = $this->withHeaders($this->withVenueAdminHeaders())
            ->postJson('/api/v1/venue-listings', [
                'organization_uuid' => $organization->uuid,
                'name' => 'Default Package Venue',
                'city' => 'Pasig City',
                'venue_type' => 'Function hall',
            ]);

        $response->assertCreated()
            ->assertJsonCount(3, 'data.packages')
            ->assertJsonPath('data.packages.0.label', 'Half day (Morning)')
            ->assertJsonPath('data.packages.2.label', 'Full-day (8 hrs)')
            ->assertJsonPath('data.default_package_id', 'full-day');
    }

    #[Test]
    public function it_requires_authentication_for_admin_endpoints(): void
    {
        auth('admin')->logout();

        $listing = $this->createVenueListing();

        $this->getJson('/api/v1/venue-listings')->assertUnauthorized();
        $this->postJson('/api/v1/venue-listings', [])->assertUnauthorized();
        $this->getJson('/api/v1/venue-listings/' . $listing->uuid)->assertUnauthorized();
        $this->putJson('/api/v1/venue-listings/' . $listing->uuid, [])->assertUnauthorized();
        $this->deleteJson('/api/v1/venue-listings/' . $listing->uuid)->assertUnauthorized();
    }
}
