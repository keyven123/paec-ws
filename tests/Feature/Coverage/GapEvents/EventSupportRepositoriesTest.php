<?php

namespace Tests\Feature\Coverage\GapEvents;

use App\Constants\GeneralConstants;
use App\Exceptions\NoBlockedDateFoundException;
use App\Http\Repositories\BlockedDateRepository;
use App\Http\Repositories\EventTicketRepository;
use App\Http\Repositories\PromoCodeRepository;
use App\Http\Repositories\ScheduleTimeRepository;
use App\Models\BlockedDate;
use App\Models\Event;
use App\Models\EventTicket;
use App\Models\EventTicketCoupon;
use App\Models\PromoCode;
use App\Models\ScheduleTime;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;

class EventSupportRepositoriesTest extends GapEventsTestCase
{
    /**
     * @return array<string, mixed>
     */
    private function promoPayload(array $overrides = []): array
    {
        return array_merge([
            'code' => 'GAP' . random_int(1000, 9999),
            'discount_type' => GeneralConstants::DISCOUNT_TYPES['AMOUNT'],
            'discount_value' => 10,
            'is_unlimited' => true,
            'usable_from' => now()->subDay(),
            'usable_to' => now()->addMonth(),
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ], $overrides);
    }

    #[Test]
    public function promoCodeCreateKeepsAnExplicitOrganization(): void
    {
        $organization = $this->createOrganization();

        $promo = app(PromoCodeRepository::class)->create($this->promoPayload([
            'organization_uuid' => $organization->uuid,
        ]));

        $this->assertSame($organization->uuid, $promo->organization_uuid);
        $this->assertDatabaseHas('promo_codes', ['uuid' => $promo->uuid, 'organization_uuid' => $organization->uuid]);
    }

    #[Test]
    public function promoCodeCreateFallsBackToTheEventOrganizationWhenTypeIsOmitted(): void
    {
        $event = $this->createEvent('Promo Event');

        $promo = app(PromoCodeRepository::class)->create($this->promoPayload([
            'activityable_id' => $event->uuid,
        ]));

        $this->assertSame($event->organization_uuid, $promo->organization_uuid);
    }

    #[Test]
    public function promoCodeUpdateResolvesTheOrganizationFromAnEventActivityable(): void
    {
        $event = $this->createEvent('Promo Event');
        $promo = PromoCode::create($this->promoPayload([
            'organization_uuid' => $this->createOrganization()->uuid,
            'activityable_type' => Event::class,
            'activityable_id' => $event->uuid,
        ]));

        app(PromoCodeRepository::class)->update($promo, ['organization_uuid' => null, 'discount_value' => 15]);

        $this->assertDatabaseHas('promo_codes', [
            'uuid' => $promo->uuid,
            'organization_uuid' => $event->organization_uuid,
            'discount_value' => 15,
        ]);
    }

    #[Test]
    public function promoCodeCreateRejectsANonEventActivityableWithoutOrganization(): void
    {
        $event = $this->createEvent('Promo Event');

        try {
            app(PromoCodeRepository::class)->create($this->promoPayload([
                'activityable_type' => 'App\\Models\\VenueListing',
                'activityable_id' => $event->uuid,
            ]));
            $this->fail('Expected a validation exception.');
        } catch (ValidationException $e) {
            $this->assertSame(
                ['Unable to determine organization for this promo code.'],
                $e->errors()['organization_uuid'],
            );
        }

        $this->assertDatabaseCount('promo_codes', 0);
    }

    #[Test]
    public function promoCodeCreateRejectsAnUnknownEventActivityable(): void
    {
        $this->expectException(ValidationException::class);

        app(PromoCodeRepository::class)->create($this->promoPayload([
            'activityable_type' => 'event',
            'activityable_id' => '00000000-0000-0000-0000-000000000000',
        ]));
    }

    #[Test]
    public function eventTicketUpdateStripsItselfFromBundleTickets(): void
    {
        $event = $this->createEvent('Bundle Event');
        $bundle = $this->createEventTicket($event, 'Bundle', 300, ['is_bundle' => true]);
        $child = $this->createEventTicket($event, 'Child', 100);

        app(EventTicketRepository::class)->update($bundle, [
            'bundle_tickets' => [$bundle->uuid, $child->uuid],
        ]);

        $this->assertSame([$child->uuid], array_values($bundle->fresh()->bundle_tickets));
    }

    #[Test]
    public function eventTicketDuplicateCopiesCoupons(): void
    {
        $event = $this->createEvent('Coupon Event');
        $original = $this->createEventTicket($event, 'Early Bird', 80, ['sold_ticket' => 7]);
        EventTicketCoupon::create(['event_ticket_uuid' => $original->uuid, 'name' => 'Free Drink', 'once_only' => true]);
        EventTicketCoupon::create(['event_ticket_uuid' => $original->uuid, 'name' => 'Parking', 'once_only' => false]);

        $duplicate = app(EventTicketRepository::class)->duplicate($original->fresh());

        $this->assertNotSame($original->uuid, $duplicate->uuid);
        $this->assertSame('Early Bird duplicate', $duplicate->name);
        $this->assertSame(GeneralConstants::GENERAL_STATUSES['INACTIVE'], $duplicate->status);
        $this->assertSame(0, $duplicate->sold_ticket);
        $this->assertEqualsCanonicalizing(
            ['Free Drink' => true, 'Parking' => false],
            $duplicate->coupons->pluck('once_only', 'name')->all(),
        );
        $this->assertSame(2, EventTicketCoupon::where('event_ticket_uuid', $original->uuid)->count());
    }

    #[Test]
    public function scheduleTimeCreateCanInheritEventTicketsFromTheSchedule(): void
    {
        $event = $this->createEvent('Timed Event');
        [$schedule] = $this->createSchedule($event, withTime: false);
        $inherited = $this->createEventTicket($event, 'Morning', 50, ['schedule_uuid' => $schedule->uuid]);
        $deleted = $this->createEventTicket($event, 'Retired', 50, ['schedule_uuid' => $schedule->uuid]);
        $deleted->delete();
        $otherSchedule = $this->createEventTicket($event, 'Elsewhere', 50);

        $time = app(ScheduleTimeRepository::class)->create([
            'schedule_uuid' => $schedule->uuid,
            'time_start' => '14:00:00',
            'time_end' => '16:00:00',
            'status' => ScheduleTime::PUBLISHED_STATUS,
            'inherit_event_tickets' => true,
            'event_ticket_uuids' => [$inherited->uuid, $deleted->uuid, $otherSchedule->uuid],
        ]);

        $copies = EventTicket::where('schedule_time_uuid', $time->uuid)->get();
        $this->assertCount(1, $copies);
        $this->assertSame('Morning', $copies->first()->name);
        $this->assertNotSame($inherited->uuid, $copies->first()->uuid);
        $this->assertNull($inherited->fresh()->schedule_time_uuid);
    }

    #[Test]
    public function blockedDateFetchOrThrowReturnsTheRecordOrThrows(): void
    {
        $event = $this->createEvent('Blocked Event');
        $blocked = BlockedDate::create([
            'blockable_type' => $event->getMorphClass(),
            'blockable_uuid' => $event->uuid,
            'blocked_date' => '2031-01-01',
        ]);
        $repository = app(BlockedDateRepository::class);

        $this->assertSame($blocked->uuid, $repository->fetchOrThrow('uuid', $blocked->uuid)->uuid);

        $this->expectException(NoBlockedDateFoundException::class);
        $repository->fetchOrThrow('uuid', '00000000-0000-0000-0000-000000000000');
    }
}
