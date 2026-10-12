<?php

namespace Tests\Feature\Coverage\GapTickets;

use App\Constants\GeneralConstants;
use App\Http\Repositories\TicketCouponRepository;
use App\Http\Repositories\TransactionRepository;
use App\Http\Repositories\VenueInquiryRepository;
use App\Http\Repositories\VenueListingRepository;
use App\Models\EventTicketCoupon;
use App\Models\Organization;
use App\Models\TicketCoupon;
use App\Models\Transaction;
use App\Models\VenueInquiry;
use App\Models\VenueListing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SmallRepositoriesTest extends TestCase
{
    use GapTicketsFixtures;
    use RefreshDatabase;

    /**
     * @return array{0: TicketCoupon, 1: Transaction}
     */
    private function couponOnNewSchedule(): array
    {
        $customer = $this->createCustomer();
        $event = $this->createEvent();
        $schedule = $this->createSchedule($event);
        $time = $this->createScheduleTime($schedule);
        $eventTicket = $this->createEventTicket($event);
        $transaction = $this->createTransaction($customer, $event, [
            'schedule_uuid' => $schedule->uuid,
            'schedule_time_uuid' => $time->uuid,
        ]);
        $ticket = $this->createTicket($transaction, $eventTicket);
        $template = EventTicketCoupon::create(['event_ticket_uuid' => $eventTicket->uuid, 'name' => 'Drink']);

        $coupon = TicketCoupon::create([
            'user_uuid' => $customer->uuid,
            'ticket_uuid' => $ticket->uuid,
            'event_uuid' => $event->uuid,
            'event_ticket_coupon_uuid' => $template->uuid,
            'name' => 'Drink',
            'qr_code' => 'COUPON_' . Str::random(8),
            'status' => GeneralConstants::TICKET_COUPON_STATUSES['ACTIVE'],
        ]);

        return [$coupon, $transaction];
    }

    #[Test]
    public function ticketCouponGetAllFiltersByEventScheduleAndTime(): void
    {
        [$target, $transaction] = $this->couponOnNewSchedule();
        $this->couponOnNewSchedule();
        $repository = app(TicketCouponRepository::class);

        $this->assertSame([$target->uuid], $repository->getAll(['event_uuid' => $target->event_uuid])->pluck('uuid')->all());
        $this->assertSame(
            [$target->uuid],
            $repository->getAll(['schedule_uuid' => $transaction->schedule_uuid])->pluck('uuid')->all(),
        );
        $this->assertSame(
            [$target->uuid],
            $repository->getAll(['schedule_time_uuid' => $transaction->schedule_time_uuid])->pluck('uuid')->all(),
        );
        $this->assertCount(2, $repository->getAll([])->get());
    }

    #[Test]
    public function transactionRecentPurchasesAreLatestFirstAndLimited(): void
    {
        $customer = $this->createCustomer();
        $event = $this->createEvent();
        $this->travelTo(now()->subHours(3));
        $oldest = $this->createTransaction($customer, $event);
        $this->travel(1)->hours();
        $middle = $this->createTransaction($customer, $event);
        $this->travel(1)->hours();
        $newest = $this->createTransaction($customer, $event);
        $this->travelBack();

        $repository = app(TransactionRepository::class);

        $limited = $repository->getRecentPurchasedTickets(['per_page' => 2])->get();
        $this->assertSame([$newest->uuid, $middle->uuid], $limited->pluck('uuid')->all());
        $this->assertTrue($limited->first()->relationLoaded('user'));
        $this->assertSame($customer->uuid, $limited->first()->user->uuid);

        $this->assertSame(
            [$newest->uuid, $middle->uuid, $oldest->uuid],
            $repository->getRecentPurchasedTickets([])->pluck('uuid')->all(),
        );
    }

    #[Test]
    public function venueInquiryUpdateReturnsFreshModel(): void
    {
        $inquiry = VenueInquiry::factory()->create();

        $updated = app(VenueInquiryRepository::class)->update($inquiry, [
            'status' => VenueInquiry::STATUSES['IN_DISCUSSION'],
        ]);

        $this->assertNotSame($inquiry, $updated);
        $this->assertSame(VenueInquiry::STATUSES['IN_DISCUSSION'], $updated->status);
        $this->assertDatabaseHas('venue_inquiries', [
            'uuid' => $inquiry->uuid,
            'status' => VenueInquiry::STATUSES['IN_DISCUSSION'],
        ]);
    }

    #[Test]
    public function venueInquiryPaginationFiltersByStatusTabAndSearch(): void
    {
        $listing = VenueListing::factory()->published()->create();
        $base = ['venue_listing_uuid' => $listing->uuid, 'site_visit' => VenueInquiry::SITE_VISIT_NO];

        $scheduled = VenueInquiry::factory()->create($base + [
            'status' => VenueInquiry::STATUSES['SITE_VISIT_SCHEDULED'],
            'full_name' => 'Scheduled Person',
            'email' => 'scheduled@test.com',
            'message' => 'plain',
        ]);
        $visitDated = VenueInquiry::factory()->create([
            'venue_listing_uuid' => $listing->uuid,
            'status' => VenueInquiry::STATUSES['IN_DISCUSSION'],
            'site_visit' => VenueInquiry::SITE_VISIT_YES,
            'visit_scheduled_date' => now()->addWeek()->toDateString(),
            'full_name' => 'Dated Person',
            'email' => 'dated@test.com',
            'message' => 'plain',
        ]);
        $searchable = VenueInquiry::factory()->create($base + [
            'status' => VenueInquiry::STATUSES['NEW'],
            'full_name' => 'Other Person',
            'email' => 'other@test.com',
            'message' => 'Need a ballroom for a wedding',
        ]);
        VenueInquiry::factory()->create(['status' => VenueInquiry::STATUSES['SITE_VISIT_SCHEDULED']]);

        $repository = app(VenueInquiryRepository::class);

        $visitTab = $repository->paginateForVenue($listing->uuid, ['status' => 'visit-schedule']);
        $this->assertEqualsCanonicalizing(
            [$scheduled->uuid, $visitDated->uuid],
            collect($visitTab->items())->pluck('uuid')->all(),
        );

        $newTab = $repository->paginateForVenue($listing->uuid, ['status' => VenueInquiry::STATUSES['NEW']]);
        $this->assertSame([$searchable->uuid], collect($newTab->items())->pluck('uuid')->all());

        $byMessage = $repository->paginateForVenue($listing->uuid, ['q' => 'ballroom']);
        $this->assertSame([$searchable->uuid], collect($byMessage->items())->pluck('uuid')->all());

        $byName = $repository->paginateForVenue($listing->uuid, ['q' => '  Dated Person ']);
        $this->assertSame([$visitDated->uuid], collect($byName->items())->pluck('uuid')->all());

        $byEmail = $repository->paginateForVenue($listing->uuid, ['q' => 'scheduled@']);
        $this->assertSame([$scheduled->uuid], collect($byEmail->items())->pluck('uuid')->all());
    }

    #[Test]
    public function venueListingCreateDerivesSlugFromName(): void
    {
        $organization = Organization::factory()->create();

        $attributes = VenueListing::factory()->raw([
            'organization_uuid' => $organization->uuid,
            'name' => 'Grand Sky Ballroom',
        ]);
        unset($attributes['slug']);

        $listing = app(VenueListingRepository::class)->create($attributes);

        $this->assertSame('grand-sky-ballroom', $listing->slug);
        $this->assertDatabaseHas('venue_listings', ['uuid' => $listing->uuid, 'slug' => 'grand-sky-ballroom']);
    }

    #[Test]
    public function venueListingUpdateNormalisesProvidedSlug(): void
    {
        $listing = VenueListing::factory()->create();

        $this->assertTrue(app(VenueListingRepository::class)->update($listing, ['slug' => 'Rooftop Garden Hall']));

        $this->assertSame('rooftop-garden-hall', $listing->fresh()->slug);
    }
}
