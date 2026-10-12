<?php

namespace Tests\Feature\Coverage\MiscControllers;

use App\Models\VenueInquiry;
use App\Models\VenueListing;
use App\Services\Chat\ChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesVenueListingFixtures;
use Tests\TestCase;

class VenueListingControllerCoverageTest extends TestCase
{
    use CreatesVenueListingFixtures;
    use MiscFixtures;
    use RefreshDatabase;

    private const MISSING_UUID = '00000000-0000-0000-0000-000000000000';

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Storage::fake('public');
    }

    private function inquiry(array $attributes = []): VenueInquiry
    {
        return VenueInquiry::factory()->create(array_merge([
            'venue_listing_uuid' => $this->createVenueListing(['status' => VenueListing::STATUSES['PUBLISHED']])->uuid,
            'site_visit' => VenueInquiry::SITE_VISIT_YES,
            'status' => VenueInquiry::STATUSES['NEW'],
        ], $attributes));
    }

    #[Test]
    public function merchantIndexIsScopedToTheirOrganization(): void
    {
        [$headers, $organization] = $this->createVenueListingMerchantAuth();
        $own = $this->createVenueListing(['organization_uuid' => $organization->uuid]);
        $this->createVenueListing();

        $this->withHeaders($headers)
            ->getJson('/api/v1/venue-listings')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.uuid', $own->uuid);
    }

    #[Test]
    public function merchantStatsAreScopedToTheirOrganization(): void
    {
        [$headers, $organization] = $this->createVenueListingMerchantAuth();
        $this->createVenueListing([
            'organization_uuid' => $organization->uuid,
            'status' => VenueListing::STATUSES['PUBLISHED'],
            'inquiries_count' => 4,
        ]);
        $this->createVenueListing(['inquiries_count' => 9]);
        $this->createVenueListing(['inquiries_count' => 1]);

        $this->withHeaders($headers)
            ->getJson('/api/v1/venue-listings/stats')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.published', 1)
            ->assertJsonPath('data.total_inquiries', 4);
    }

    #[Test]
    public function updateReturns404ForUnknownListing(): void
    {
        $this->withHeaders($this->adminHeaders())
            ->putJson('/api/v1/venue-listings/' . self::MISSING_UUID, ['name' => 'Renamed'])
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Venue listing not found']);
    }

    #[Test]
    public function destroyReturns404ForUnknownListing(): void
    {
        $this->withHeaders($this->adminHeaders())
            ->deleteJson('/api/v1/venue-listings/' . self::MISSING_UUID)
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Venue listing not found']);
    }

    #[Test]
    public function storeInquiryReturns404ForUnknownSlug(): void
    {
        $this->postJson('/api/v1/public/venue-listings/no-such-venue/inquiries', [
            'full_name' => 'Juan Dela Cruz',
            'email' => 'juan@test.com',
        ])
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Venue not found']);

        $this->assertSame(0, VenueInquiry::count());
    }

    #[Test]
    public function storeInquirySucceedsEvenWhenInitialChatMessageFails(): void
    {
        $listing = $this->createVenueListing(['status' => VenueListing::STATUSES['PUBLISHED']]);
        $customer = $this->createCustomer();

        $this->mock(ChatService::class)
            ->shouldReceive('sendMessage')
            ->once()
            ->andThrow(new \RuntimeException('chat backend down'));
        Log::spy();

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . auth('api')->login($customer)])
            ->postJson("/api/v1/public/venue-listings/{$listing->slug}/inquiries", [
                'full_name' => 'Juan Dela Cruz',
                'email' => 'juan@test.com',
                'initial_chat_message' => 'Is the venue free in December?',
            ])
            ->assertCreated();

        $inquiryUuid = $response->json('data.uuid');
        $this->assertDatabaseHas('venue_inquiries', ['uuid' => $inquiryUuid, 'user_uuid' => $customer->uuid]);
        Log::shouldHaveReceived('error')->once()->with(
            'Failed to send initial inquiry chat message',
            ['inquiry_uuid' => $inquiryUuid, 'error' => 'chat backend down'],
        );
    }

    #[Test]
    public function updateInquiryCancelsInquiry(): void
    {
        $inquiry = $this->inquiry();

        $this->withHeaders($this->adminHeaders())
            ->patchJson("/api/v1/venue-listings/inquiries/{$inquiry->uuid}", ['cancel' => true])
            ->assertOk()
            ->assertJsonPath('data.status', VenueInquiry::STATUSES['CANCELLED']);

        $this->assertSame(VenueInquiry::STATUSES['CANCELLED'], $inquiry->fresh()->status);
    }

    #[Test]
    public function updateInquiryWithoutChangesReturnsInquiryUnchanged(): void
    {
        $inquiry = $this->inquiry();

        $this->withHeaders($this->adminHeaders())
            ->patchJson("/api/v1/venue-listings/inquiries/{$inquiry->uuid}", [])
            ->assertOk()
            ->assertJsonPath('data.uuid', $inquiry->uuid)
            ->assertJsonPath('data.status', VenueInquiry::STATUSES['NEW']);

        $this->assertSame(VenueInquiry::STATUSES['NEW'], $inquiry->fresh()->status);
    }

    #[Test]
    public function updateInquiryReschedulesDateKeepingExistingVisitTime(): void
    {
        $inquiry = $this->inquiry([
            'status' => VenueInquiry::STATUSES['SITE_VISIT_SCHEDULED'],
            'visit_scheduled_date' => now()->addDays(3)->toDateString(),
            'visit_scheduled_time' => '14:30:00',
        ]);
        $newDate = now()->addDays(10)->toDateString();

        $this->withHeaders($this->adminHeaders())
            ->patchJson("/api/v1/venue-listings/inquiries/{$inquiry->uuid}", ['visit_scheduled_date' => $newDate])
            ->assertOk()
            ->assertJsonPath('data.status', VenueInquiry::STATUSES['SITE_VISIT_SCHEDULED']);

        $inquiry->refresh();
        $this->assertSame($newDate, $inquiry->visit_scheduled_date->format('Y-m-d'));
        $this->assertSame('14:30', substr((string) $inquiry->visit_scheduled_time, 0, 5));
    }

    public static function inquiryActions(): array
    {
        return [
            'update' => ['patch', '', []],
            'proposal' => ['post', '/proposal', ['proposal_amount' => 1000, 'proposal_valid_until' => '2030-01-01', 'file' => 'pdf']],
            'deposit' => ['post', '/deposit-request', ['deposit_amount' => 500, 'deposit_due_date' => '2030-01-01']],
            'final billing' => ['post', '/final-billing', ['balance_amount' => 500, 'balance_due_date' => '2030-01-01']],
            'complete' => ['post', '/complete', []],
        ];
    }

    #[Test]
    #[DataProvider('inquiryActions')]
    public function inquiryActionsReturn404ForUnknownInquiry(string $method, string $suffix, array $payload): void
    {
        if (($payload['file'] ?? null) === 'pdf') {
            $payload['file'] = UploadedFile::fake()->create('proposal.pdf', 10, 'application/pdf');
        }

        $this->withHeaders($this->adminHeaders())
            ->json(strtoupper($method), '/api/v1/venue-listings/inquiries/' . self::MISSING_UUID . $suffix, $payload)
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Inquiry not found']);
    }
}
