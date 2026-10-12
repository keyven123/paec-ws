<?php

namespace Tests\Feature\Coverage\ResourcesDirect;

use App\Constants\GeneralConstants;
use App\Http\Resources\BrowseByCityLocationResource;
use App\Http\Resources\ChatMessageResource;
use App\Http\Resources\EventTicketMarkupResource;
use App\Http\Resources\EventTicketPublicResource;
use App\Http\Resources\PlatformNotificationResource;
use App\Http\Resources\PublicPlaceResource;
use App\Http\Resources\TempTransactionResource;
use App\Http\Resources\TicketResource;
use App\Http\Resources\TransactionResource;
use App\Http\Resources\TransactionVenueInquiryResource;
use App\Http\Resources\VenueInquiryResource;
use App\Models\AdminUser;
use App\Models\ChatMessage;
use App\Models\EventLocation;
use App\Models\EventTicket;
use App\Models\Place;
use App\Models\PlatformNotification;
use App\Models\Schedule;
use App\Models\ScheduleTime;
use App\Models\TempTransaction;
use App\Models\Ticket;
use App\Models\Transaction;
use App\Models\VenueInquiry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;

class ResourceEdgeCasesCoverageTest extends ResourcesDirectTestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function ticketRendersTransferRecipientSeatCreatorLocationAndTodaysVirtualLink(): void
    {
        Carbon::setTestNow('2026-10-12 09:00:00');

        $transaction = $this->inMemory(Transaction::class, ['uuid' => 'txn-uuid'], [
            'schedule' => $this->inMemory(Schedule::class, ['uuid' => 'schedule-uuid', 'date_from' => '2026-10-12', 'date_to' => '2026-10-12']),
            'scheduleTime' => null,
            'transactionOrders' => new Collection(),
        ]);
        $venueSeat = ResourceStub::make(['uuid' => 'seat-uuid']);
        $venueSeat->setRelation('venue', ResourceStub::make(['uuid' => 'venue-uuid', 'name' => 'Main Hall']));

        $ticket = $this->inMemory(Ticket::class, ['uuid' => 'ticket-uuid', 'status' => 'active', 'price' => 100], [
            'transaction' => $transaction,
            'transferredToUser' => ResourceStub::make(['uuid' => 'recipient-uuid']),
            'eventTicket' => ResourceStub::make(['is_virtual' => true, 'virtual_event_url' => 'https://meet.test/room']),
            'creator' => ResourceStub::make(['uuid' => 'creator-uuid']),
            'venueSeat' => $venueSeat,
            'eventLocation' => $this->inMemory(EventLocation::class, [
                'uuid' => 'location-uuid',
                'name' => null,
                'city' => 'Makati',
                'address' => '1 Ayala Ave',
            ]),
        ]);

        $data = $this->render(new TicketResource($ticket));

        $this->assertSame(['uuid' => 'recipient-uuid', 'name' => 'Ana Cruz', 'email' => 'ana@example.com'], $data['transferred_to_user']);
        $this->assertSame('https://meet.test/room', $data['event_ticket']['virtual_event_url']);
        $this->assertSame(['uuid' => 'creator-uuid', 'name' => 'Ana Cruz'], $data['creator']);
        $this->assertSame(['uuid' => 'seat-uuid', 'venue' => ['uuid' => 'venue-uuid', 'name' => 'Main Hall']], $data['venue_seat']);
        $this->assertSame([
            'uuid' => 'location-uuid',
            'name' => null,
            'city' => 'Makati',
            'address' => '1 Ayala Ave',
            'label' => '1 Ayala Ave',
        ], $data['event_location']);
    }

    #[Test]
    public function transactionShowsLedgerCommissionToPlatformAdmins(): void
    {
        $admin = (new AdminUser())->forceFill(['uuid' => 'admin-uuid']);
        $admin->setRelation('role', ResourceStub::make(['is_admin' => true]));
        $this->actingAs($admin, 'admin');

        $transaction = $this->inMemory(Transaction::class, ['affiliate_partner_uuid' => 'partner-uuid'], [
            'commissionLedger' => ResourceStub::make(['agent_commission' => '12.50', 'agent_commission_percent' => '5']),
        ]);

        $data = $this->render(new TransactionResource($transaction));

        $this->assertSame(12.5, $data['affiliate_commission_amount']);
        $this->assertSame(5, $data['affiliate_commission_percent']);
    }

    #[Test]
    public function transactionVenueInquiryAddsTheLoadedListing(): void
    {
        $inquiry = $this->inMemory(VenueInquiry::class, ['status' => 'new', 'site_visit' => VenueInquiry::SITE_VISIT_NO], [
            'venueListing' => ResourceStub::make([
                'slug' => 'main-hall',
                'address' => '1 Ayala Ave',
                'location' => 'Ayala',
                'city' => 'Makati',
                'region' => 'NCR',
                'area' => 'Metro Manila',
                'venue_type' => 'hall',
            ]),
        ]);

        $data = $this->render(new TransactionVenueInquiryResource($inquiry));

        $this->assertSame('No, proceed to booking', $data['site_visit_label']);
        $this->assertSame([
            'uuid' => 'stub-uuid',
            'slug' => 'main-hall',
            'name' => 'Stub Name',
            'address' => '1 Ayala Ave',
            'location' => 'Ayala',
            'city' => 'Makati',
            'region' => 'NCR',
            'area' => 'Metro Manila',
            'type' => 'hall',
            'venue_type' => 'hall',
        ], $data['venue_listing']);
    }

    #[Test]
    public function venueInquiryInfersSiteVisitFromTheLegacyMessage(): void
    {
        $yes = $this->render(new VenueInquiryResource($this->inMemory(VenueInquiry::class, [
            'status' => 'new',
            'message' => "Hello\nSite visit requested: Yes",
        ])));
        $no = $this->render(new VenueInquiryResource($this->inMemory(VenueInquiry::class, [
            'status' => 'new',
            'message' => "Hello\nSite visit requested: No",
        ])));

        $this->assertSame(VenueInquiry::SITE_VISIT_YES, $yes['site_visit']);
        $this->assertSame(VenueInquiry::SITE_VISIT_NO, $no['site_visit']);
        $this->assertSame('2026-10-12', VenueInquiryResource::visitScheduledLabel('2026-10-12', null));
    }

    #[Test]
    public function browseByCityPrefersFeaturedThenPortraitThenLogo(): void
    {
        $render = function (array $images): ?array {
            $event = ResourceStub::make();
            foreach ($images as $relation => $url) {
                $event->setRelation($relation, $url ? ResourceStub::make(['url' => $url]) : null);
            }

            return $this->render(new BrowseByCityLocationResource(
                $this->inMemory(EventLocation::class, ['city' => 'Makati'], ['event' => $event])
            ))['image'];
        };

        $this->assertSame(['url' => 'https://cdn.test/featured.png'], $render([
            'featuredImage' => 'https://cdn.test/featured.png',
            'portraitImage' => 'https://cdn.test/portrait.png',
        ]));
        $this->assertSame(['url' => 'https://cdn.test/portrait.png'], $render([
            'featuredImage' => null,
            'portraitImage' => 'https://cdn.test/portrait.png',
        ]));
        $this->assertSame(['url' => 'https://cdn.test/logo.png'], $render([
            'featuredImage' => null,
            'portraitImage' => null,
            'logo' => 'https://cdn.test/logo.png',
        ]));
    }

    #[Test]
    public function eventTicketMarkupLabelsPercentageAndAmountDiscounts(): void
    {
        $label = fn (string $type, string $value) => $this->render(new EventTicketMarkupResource(
            $this->inMemory(EventTicket::class, ['price' => 1000, 'discount_type' => $type, 'discount_value' => $value])
        ))['merchant_discount_label'];

        $this->assertSame('12.5% off base price', $label(GeneralConstants::DISCOUNT_TYPES['PERCENTAGE'], '12.50'));
        $this->assertSame('₱150 off base price', $label(GeneralConstants::DISCOUNT_TYPES['AMOUNT'], '150.00'));
    }

    #[Test]
    public function eventTicketPublicNestsTheScheduleWhenEagerLoaded(): void
    {
        $scheduleTime = $this->inMemory(ScheduleTime::class, ['uuid' => 'time-uuid', 'time_start' => '09:00:00', 'time_end' => '17:00:00'], [
            'schedule' => $this->inMemory(Schedule::class, ['uuid' => 'schedule-uuid', 'date_from' => '2026-10-12', 'date_to' => '2026-10-13']),
        ]);

        $data = $this->render(new EventTicketPublicResource(
            $this->inMemory(EventTicket::class, ['price' => 500, 'max_ticket' => 10, 'sold_ticket' => 0], ['scheduleTime' => $scheduleTime])
        ));

        $this->assertSame([
            'uuid' => 'schedule-uuid',
            'date_from' => '2026-10-12',
            'date_to' => '2026-10-13',
        ], $data['schedule_time']['schedule']);
    }

    #[Test]
    public function chatMessageDropsAnAttachmentWhoseUploadIsGone(): void
    {
        $message = $this->inMemory(ChatMessage::class, ['attachment_upload_uuid' => 'deleted-upload'], ['attachment' => null]);

        $this->assertNull($this->render(new ChatMessageResource($message))['attachment']);
    }

    #[Test]
    public function tempTransactionHasNoComplianceNoteWithoutAnEvent(): void
    {
        $data = $this->render(new TempTransactionResource($this->inMemory(TempTransaction::class, ['sub_total' => 100])));

        $this->assertNull($data['compliance_included_note']);
    }

    #[Test]
    public function platformNotificationReportsReadState(): void
    {
        $notification = $this->inMemory(PlatformNotification::class, [
            'uuid' => 'notification-uuid',
            'type' => 'event_submitted',
            'title' => 'Event submitted',
            'body' => 'Needs review',
            'action_url' => '/admin/events/1',
            'data' => ['event_uuid' => 'event-uuid'],
            'read_at' => '2026-10-12 08:00:00',
            'created_at' => '2026-10-12 07:00:00',
        ]);

        $data = $this->render(new PlatformNotificationResource($notification));

        $this->assertSame('notification-uuid', $data['uuid']);
        $this->assertSame(['event_uuid' => 'event-uuid'], $data['data']);
        $this->assertTrue($data['is_read']);
        $this->assertNotNull($data['read_at']);
        $this->assertNotNull($data['created_at']);
    }

    #[Test]
    public function publicPlaceExposesOnlyNameAddressAndImage(): void
    {
        $place = $this->inMemory(Place::class, ['name' => 'Rizal Park', 'address' => 'Manila', 'image_url' => 'https://cdn.test/park.png']);

        $this->assertSame([
            'name' => 'Rizal Park',
            'address' => 'Manila',
            'image_url' => 'https://cdn.test/park.png',
        ], $this->render(new PublicPlaceResource($place)));
    }
}
