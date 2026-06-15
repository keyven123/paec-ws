<?php

namespace Tests\Feature;

use App\Constants\GeneralConstants;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Models\VenueInquiry;
use App\Models\VenueListing;
use App\Notifications\VenueInquirySubmittedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesVenueListingFixtures;
use Tests\TestCase;

class VenueListingPublicControllerTest extends TestCase
{
    use CreatesVenueListingFixtures;
    use RefreshDatabase;

    #[Test]
    public function it_can_list_public_venue_listings(): void
    {
        $published = $this->createVenueListing([
            'slug' => 'published-venue-hall',
            'name' => 'Published Venue Hall',
            'status' => VenueListing::STATUSES['PUBLISHED'],
        ]);
        $this->attachListingImages($published);

        $this->createVenueListing([
            'slug' => 'draft-venue-hall',
            'status' => VenueListing::STATUSES['DRAFT'],
        ]);

        $response = $this->getJson('/api/v1/public/venue-listings');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'published-venue-hall')
            ->assertJsonPath('data.0.name', 'Published Venue Hall')
            ->assertJsonPath('data.0.featured_image_url', 'https://example.com/published-venue-hall-featured.jpg')
            ->assertJsonCount(3, 'data.0.gallery_image_urls')
            ->assertJsonPath('data.0.photo_count', 4);
    }

    #[Test]
    public function it_includes_approved_listings_in_public_results(): void
    {
        $approved = $this->createVenueListing([
            'slug' => 'approved-venue-hall',
            'status' => VenueListing::STATUSES['APPROVED'],
        ]);

        $response = $this->getJson('/api/v1/public/venue-listings');

        $response->assertOk()
            ->assertJsonFragment(['slug' => $approved->slug]);
    }

    #[Test]
    public function it_can_filter_public_listings_by_category(): void
    {
        $this->createVenueListing([
            'slug' => 'function-hall-venue',
            'category' => VenueListing::CATEGORIES['FUNCTION_HALLS'],
            'status' => VenueListing::STATUSES['PUBLISHED'],
        ]);

        $this->createVenueListing([
            'slug' => 'conference-venue',
            'category' => VenueListing::CATEGORIES['CONFERENCE'],
            'status' => VenueListing::STATUSES['PUBLISHED'],
        ]);

        $response = $this->getJson('/api/v1/public/venue-listings?category=conference');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'conference-venue');
    }

    #[Test]
    public function it_can_show_public_venue_detail_by_slug(): void
    {
        $listing = $this->createVenueListing([
            'slug' => 'detail-venue-hall',
            'name' => 'Detail Venue Hall',
            'description' => 'A spacious hall for corporate events.',
            'status' => VenueListing::STATUSES['PUBLISHED'],
        ]);
        $this->attachListingImages($listing);

        $response = $this->getJson('/api/v1/public/venue-listings/detail-venue-hall');

        $response->assertOk()
            ->assertJsonPath('data.slug', 'detail-venue-hall')
            ->assertJsonPath('data.listing.name', 'Detail Venue Hall')
            ->assertJsonPath('data.about', 'A spacious hall for corporate events.')
            ->assertJsonPath('data.featured_image_url', 'https://example.com/detail-venue-hall-featured.jpg')
            ->assertJsonCount(3, 'data.gallery_image_urls')
            ->assertJsonPath('data.photo_count', 4)
            ->assertJsonStructure([
                'data' => [
                    'listing',
                    'packages',
                    'setups',
                    'specs',
                    'best_for',
                    'amenities',
                    'reviews',
                ],
            ]);
    }

    #[Test]
    public function it_exposes_packages_with_time_blocks_on_public_venue_detail(): void
    {
        $listing = $this->createVenueListing([
            'slug' => 'packaged-public-venue',
            'status' => VenueListing::STATUSES['PUBLISHED'],
            'packages' => [
                [
                    'id' => 'full-day',
                    'label' => 'Full-day (8 hrs)',
                    'priceFrom' => 45000,
                    'note' => 'Full-day package · final rate varies by date & setup',
                    'start_time' => '07:00',
                    'end_time' => '21:00',
                    'crosses_midnight' => false,
                    'sort_order' => 0,
                    'time_label' => '7:00 AM – 9:00 PM',
                ],
            ],
            'default_package_id' => 'full-day',
        ]);

        $this->getJson('/api/v1/public/venue-listings/' . $listing->slug)
            ->assertOk()
            ->assertJsonPath('data.packages.0.label', 'Full-day (8 hrs)')
            ->assertJsonPath('data.packages.0.time_label', '7:00 AM – 9:00 PM')
            ->assertJsonPath('data.default_package_id', 'full-day');
    }

    #[Test]
    public function it_masks_contact_information_in_public_venue_detail(): void
    {
        $listing = $this->createVenueListing([
            'slug' => 'masked-contact-venue',
            'status' => VenueListing::STATUSES['PUBLISHED'],
            'description' => 'Reach us at venue@example.com or 0917 123 4567 for direct booking.',
            'specs' => [
                ['label' => 'Coordinator', 'value' => 'Jane · jane@venue.com · 0999 888 7777'],
            ],
            'packages' => [
                [
                    'id' => 'full-day',
                    'label' => 'Full-day (8 hrs)',
                    'priceFrom' => 45000,
                    'note' => 'WhatsApp +63 917 555 1234 for rush bookings',
                    'start_time' => '07:00',
                    'end_time' => '21:00',
                ],
            ],
        ]);

        $response = $this->getJson('/api/v1/public/venue-listings/' . $listing->slug);

        $response->assertOk()
            ->assertJsonPath('data.about', fn ($value) => is_string($value)
                && ! str_contains($value, 'venue@example.com')
                && ! str_contains($value, '0917')
                && str_contains($value, '[hidden]'))
            ->assertJsonPath('data.specs.0.value', fn ($value) => is_string($value)
                && ! str_contains($value, 'jane@venue.com')
                && str_contains($value, '[hidden]'))
            ->assertJsonPath('data.packages.0.note', fn ($value) => is_string($value)
                && ! str_contains($value, '917')
                && str_contains($value, '[hidden]'));
    }

    #[Test]
    public function it_returns_404_for_non_public_venue_detail(): void
    {
        $this->createVenueListing([
            'slug' => 'hidden-draft-venue',
            'status' => VenueListing::STATUSES['DRAFT'],
        ]);

        $this->getJson('/api/v1/public/venue-listings/hidden-draft-venue')
            ->assertNotFound()
            ->assertJsonPath('message', 'Venue not found');
    }

    #[Test]
    public function it_can_submit_a_venue_inquiry(): void
    {
        Notification::fake();

        $listing = $this->createVenueListing([
            'slug' => 'inquiry-venue-hall',
            'status' => VenueListing::STATUSES['PUBLISHED'],
            'inquiries_count' => 2,
        ]);
        $listing->load('organization');

        $payload = [
            'full_name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'phone' => '09171234567',
            'event_type' => 'Wedding',
            'event_date' => now()->addMonths(2)->toDateString(),
            'guest_count' => 150,
            'site_visit' => VenueInquiry::SITE_VISIT_YES,
            'message' => 'Interested in a site visit next week.',
        ];

        $response = $this->postJson('/api/v1/public/venue-listings/inquiry-venue-hall/inquiries', $payload);

        $response->assertCreated()
            ->assertJsonPath('data.full_name', 'Jane Doe')
            ->assertJsonPath('data.email', 'jane@example.com')
            ->assertJsonPath('data.status', VenueInquiry::STATUSES['NEW']);

        $this->assertDatabaseHas('venue_inquiries', [
            'venue_listing_uuid' => $listing->uuid,
            'email' => 'jane@example.com',
            'full_name' => 'Jane Doe',
        ]);

        $this->assertDatabaseHas('venue_listings', [
            'uuid' => $listing->uuid,
            'inquiries_count' => 3,
        ]);

        Notification::assertSentTo(
            $listing->organization,
            VenueInquirySubmittedNotification::class,
            fn (VenueInquirySubmittedNotification $notification) => $notification->inquiryUuid === $response->json('data.uuid'),
        );
    }

    #[Test]
    public function it_does_not_notify_organization_when_email_is_missing(): void
    {
        Notification::fake();

        $organization = Organization::factory()->create(['email' => null]);
        $listing = $this->createVenueListing([
            'slug' => 'no-org-email-venue',
            'status' => VenueListing::STATUSES['PUBLISHED'],
            'organization_uuid' => $organization->uuid,
        ]);

        $this->postJson('/api/v1/public/venue-listings/no-org-email-venue/inquiries', [
            'full_name' => 'John Smith',
            'email' => 'john@example.com',
            'event_date' => now()->addMonth()->toDateString(),
            'guest_count' => 80,
            'site_visit' => VenueInquiry::SITE_VISIT_NO,
        ])->assertCreated();

        Notification::assertNothingSent();
    }

    #[Test]
    public function it_sends_initial_chat_message_when_customer_submits_inquiry_with_questions(): void
    {
        Notification::fake();

        $customerRole = Role::create([
            'name' => 'Customer',
            'code' => GeneralConstants::ROLES['CUSTOMER']['name'],
        ]);

        $customer = User::factory()->create([
            'role_uuid' => $customerRole->uuid,
            'email' => 'inquiry-customer@test.com',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
        ]);

        $listing = $this->createVenueListing([
            'slug' => 'chat-inquiry-venue',
            'status' => VenueListing::STATUSES['PUBLISHED'],
        ]);

        $response = $this->actingAs($customer, 'api')
            ->postJson('/api/v1/public/venue-listings/chat-inquiry-venue/inquiries', [
                'full_name' => 'Jane Doe',
                'email' => 'inquiry-customer@test.com',
                'event_type' => 'Wedding',
                'event_date' => now()->addMonths(2)->toDateString(),
                'guest_count' => 120,
                'site_visit' => VenueInquiry::SITE_VISIT_YES,
                'message' => "Package: Full Day from ₱50,000\nTime: 9:00 AM – 6:00 PM\nSetup: Theater (up to 200 pax)",
                'initial_chat_message' => 'Do you allow external catering?',
            ]);

        $inquiryUuid = $response->assertCreated()->json('data.uuid');

        $this->assertDatabaseHas('chat_threads', [
            'venue_inquiry_uuid' => $inquiryUuid,
            'customer_uuid' => $customer->uuid,
        ]);

        $this->assertDatabaseHas('chat_messages', [
            'sender_type' => ChatThread::SENDER_CUSTOMER,
            'sender_uuid' => $customer->uuid,
            'body' => 'Do you allow external catering?',
            'message_type' => ChatMessage::TYPE_TEXT,
        ]);
    }

    #[Test]
    public function it_rejects_inquiry_on_unavailable_event_date(): void
    {
        $this->setUpVenueListingAdmin();

        $listing = $this->createVenueListing([
            'slug' => 'blocked-date-inquiry-venue',
            'status' => VenueListing::STATUSES['PUBLISHED'],
        ]);

        $blockedDate = now()->addMonths(3)->toDateString();

        $this->withHeaders($this->withVenueAdminHeaders())
            ->postJson('/api/v1/venue-listings/' . $listing->uuid . '/blocked-dates', [
                'blocked_date' => $blockedDate,
                'reason' => 'Closed',
            ])
            ->assertCreated();

        $this->postJson('/api/v1/public/venue-listings/blocked-date-inquiry-venue/inquiries', [
            'full_name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'event_date' => $blockedDate,
            'guest_count' => 100,
            'site_visit' => VenueInquiry::SITE_VISIT_NO,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['event_date']);

        $this->assertDatabaseMissing('venue_inquiries', [
            'venue_listing_uuid' => $listing->uuid,
            'email' => 'jane@example.com',
        ]);
    }

    #[Test]
    public function it_validates_venue_inquiry_payload(): void
    {
        $this->createVenueListing([
            'slug' => 'validate-inquiry-venue',
            'status' => VenueListing::STATUSES['PUBLISHED'],
        ]);

        $response = $this->postJson('/api/v1/public/venue-listings/validate-inquiry-venue/inquiries', [
            'email' => 'not-an-email',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['full_name', 'email']);
    }
}
