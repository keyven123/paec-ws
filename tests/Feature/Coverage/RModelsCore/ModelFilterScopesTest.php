<?php

namespace Tests\Feature\Coverage\RModelsCore;

use App\Models\BlockedDate;
use App\Models\EventTicket;
use App\Models\EventTicketCoupon;
use App\Models\Schedule;
use App\Models\ScheduleTime;
use App\Models\TempTransaction;
use App\Models\Ticket;
use App\Models\TicketCoupon;
use App\Models\TicketSeat;
use App\Models\Venue;
use App\Models\VenueSeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ModelFilterScopesTest extends TestCase
{
    use RefreshDatabase;
    use RModelsCoreFixtures;

    private function venueSeat(Venue $venue, array $attributes = []): VenueSeat
    {
        return VenueSeat::create(array_merge([
            'venue_uuid' => $venue->uuid,
            'col' => 1,
            'row' => 'A',
            'seat_no' => 1,
            'category' => 'regular',
            'color' => '#000000',
            'status' => 'active',
        ], $attributes));
    }

    private function issuedTicket(): Ticket
    {
        $occurrence = $this->makeEventOccurrence('2026-06-20');

        return $this->makeTicket($this->makeTransaction($this->makeCustomer(), $occurrence), $occurrence);
    }

    #[Test]
    public function venueSeatFiltersMatchEachColumn(): void
    {
        $venue = Venue::factory()->create();
        $target = $this->venueSeat($venue, [
            'col' => 7, 'row' => 'Q', 'seat_no' => 77, 'category' => 'vip', 'color' => '#ff0000', 'status' => 'blocked',
        ]);
        $other = $this->venueSeat(Venue::factory()->create());

        $cases = [
            ['q' => 'Q'],
            ['q' => '77'],
            ['venue_uuid' => $venue->uuid],
            ['category' => 'vip'],
            ['color' => '#ff0000'],
            ['status' => 'blocked'],
            ['col' => 7],
            ['row' => 'Q'],
        ];

        foreach ($cases as $filters) {
            $this->assertSameModels([$target], VenueSeat::filters($filters)->get(), json_encode($filters));
        }
        $this->assertSameModels([$target, $other], VenueSeat::filters([])->get());
    }

    #[Test]
    public function ticketSeatFiltersMatchEachColumn(): void
    {
        $venue = Venue::factory()->create();
        $seatA = $this->venueSeat($venue);
        $seatB = $this->venueSeat($venue, ['seat_no' => 2]);
        $ticket = $this->issuedTicket();

        $target = TicketSeat::create([
            'ticket_uuid' => $ticket->uuid, 'venue_uuid' => $venue->uuid, 'venue_seat_uuid' => $seatA->uuid,
            'col' => 'K', 'row' => 3, 'seat_no' => 31, 'category' => 'vip', 'color' => '#00ff00', 'status' => 'held',
        ]);
        TicketSeat::create([
            'ticket_uuid' => $this->issuedTicket()->uuid, 'venue_uuid' => $venue->uuid, 'venue_seat_uuid' => $seatB->uuid,
            'col' => 'B', 'row' => 1, 'seat_no' => 2, 'category' => 'regular', 'color' => '#000000', 'status' => 'active',
        ]);

        $cases = [
            ['q' => 'K'],
            ['q' => '31'],
            ['ticket_uuid' => $ticket->uuid],
            ['venue_seat_uuid' => $seatA->uuid],
            ['category' => 'vip'],
            ['color' => '#00ff00'],
            ['status' => 'held'],
            ['col' => 'K'],
            ['row' => 3],
        ];

        foreach ($cases as $filters) {
            $this->assertSameModels([$target], TicketSeat::filters($filters)->get(), json_encode($filters));
        }
        $this->assertTrue($target->venue->is($venue));
    }

    #[Test]
    public function scheduleFiltersMatchKeywordStatusEventAndDateRange(): void
    {
        $june = $this->makeEventOccurrence('2026-06-10');
        $june['event']->update(['event_name' => 'Nebula Fest']);
        $july = $this->makeEventOccurrence('2026-07-10');
        $july['schedule']->update(['status' => 'draft']);

        $this->assertSameModels([$june['schedule']], Schedule::filters(['q' => 'Nebula'])->get());
        $this->assertSameModels([$july['schedule']], Schedule::filters(['status' => 'draft'])->get());
        $this->assertSameModels([$june['schedule']], Schedule::filters(['event_uuid' => $june['event']->uuid])->get());
        $this->assertSameModels([$july['schedule']], Schedule::filters(['date_from' => '2026-07-01'])->get());
        $this->assertSameModels([$june['schedule']], Schedule::filters(['date_to' => '2026-06-30'])->get());
    }

    #[Test]
    public function scheduleTimeFiltersMatchKeywordStatusScheduleAndTimeRange(): void
    {
        $morning = $this->makeEventOccurrence('2026-06-10', '08:00:00');
        $morning['event']->update(['event_description' => 'Sunrise yoga by the bay']);
        $morning['scheduleTime']->update(['time_end' => '09:00:00']);
        $evening = $this->makeEventOccurrence('2026-06-10', '18:00:00');
        $evening['scheduleTime']->update(['status' => 'draft']);

        $this->assertSameModels([$morning['scheduleTime']], ScheduleTime::filters(['q' => 'Sunrise'])->get());
        $this->assertSameModels([$evening['scheduleTime']], ScheduleTime::filters(['status' => 'draft'])->get());
        $this->assertSameModels(
            [$morning['scheduleTime']],
            ScheduleTime::filters(['schedule_uuid' => $morning['schedule']->uuid])->get(),
        );
        $this->assertSameModels([$evening['scheduleTime']], ScheduleTime::filters(['time_start' => '12:00:00'])->get());
        $this->assertSameModels([$morning['scheduleTime']], ScheduleTime::filters(['time_end' => '12:00:00'])->get());
    }

    #[Test]
    public function eventTicketFiltersMatchKeywordBundleAvailabilityAndPrice(): void
    {
        $event = $this->makeEvent();
        $target = EventTicket::create([
            'event_uuid' => $event->uuid, 'code' => 'VIPX-1', 'name' => 'Platinum Pass', 'description' => 'Best seats',
            'price' => 2500, 'is_bundle' => true, 'bundle_quantity' => 2,
            'available_from' => '2026-07-01 00:00:00', 'available_to' => '2026-07-31 00:00:00',
        ]);
        $other = EventTicket::create([
            'event_uuid' => $event->uuid, 'code' => 'GEN-' . Str::random(4), 'name' => 'General', 'description' => 'Standing',
            'price' => 100, 'is_bundle' => false,
            'available_from' => '2026-05-01 00:00:00', 'available_to' => '2026-09-30 00:00:00',
        ]);

        $this->assertSameModels([$target], EventTicket::filters(['q' => 'Platinum'])->get());
        $this->assertSameModels([$target], EventTicket::filters(['q' => 'VIPX'])->get());
        $this->assertSameModels([$target], EventTicket::filters(['is_bundle' => true])->get());
        $this->assertSameModels([$target], EventTicket::filters(['available_from' => '2026-06-01'])->get());
        $this->assertSameModels([$target], EventTicket::filters(['available_to' => '2026-08-01'])->get());
        $this->assertSameModels([$target], EventTicket::filters(['min_price' => 1000])->get());
        $this->assertSameModels([$other], EventTicket::filters(['max_price' => 1000])->get());
    }

    #[Test]
    public function ticketCouponFiltersMatchKeywordAndStatus(): void
    {
        $ticket = $this->issuedTicket();
        $eventCoupon = EventTicketCoupon::create(['event_ticket_uuid' => $ticket->event_ticket_uuid, 'name' => 'Freebies']);
        $base = [
            'user_uuid' => $ticket->user_uuid,
            'ticket_uuid' => $ticket->uuid,
            'event_uuid' => $ticket->event_uuid,
            'event_ticket_coupon_uuid' => $eventCoupon->uuid,
        ];
        $drink = TicketCoupon::create($base + ['name' => 'Free Drink', 'qr_code' => 'CPN-DRINK', 'status' => 'claimed']);
        $popcorn = TicketCoupon::create($base + ['name' => 'Popcorn', 'qr_code' => 'CPN-POP', 'status' => 'unclaimed']);

        $this->assertSameModels([$drink], TicketCoupon::filters(['q' => 'Drink'])->get());
        $this->assertSameModels([$popcorn], TicketCoupon::filters(['q' => 'CPN-POP'])->get());
        $this->assertSameModels([$popcorn], TicketCoupon::filters(['status' => 'unclaimed'])->get());
    }

    #[Test]
    public function tempTransactionFiltersMatchScheduleTimeAndVoucher(): void
    {
        $customer = $this->makeCustomer();
        $occurrence = $this->makeEventOccurrence('2026-06-20');
        $target = TempTransaction::create([
            'user_uuid' => $customer->uuid,
            'event_uuid' => $occurrence['event']->uuid,
            'schedule_uuid' => $occurrence['schedule']->uuid,
            'schedule_time_uuid' => $occurrence['scheduleTime']->uuid,
            'sub_total' => 100,
            'total_amount' => 100,
        ]);
        $voucherUuid = (string) Str::uuid();
        $target->forceFill(['voucher_uuid' => $voucherUuid])->save();
        TempTransaction::create(['user_uuid' => $customer->uuid, 'sub_total' => 50, 'total_amount' => 50]);

        $this->assertSameModels([$target], TempTransaction::filters(['schedule_uuid' => $occurrence['schedule']->uuid])->get());
        $this->assertSameModels(
            [$target],
            TempTransaction::filters(['schedule_time_uuid' => $occurrence['scheduleTime']->uuid])->get(),
        );
        $this->assertSameModels([$target], TempTransaction::filters(['voucher_uuid' => $voucherUuid])->get());
    }

    #[Test]
    public function blockedDateKeywordFilterMatchesReason(): void
    {
        $event = $this->makeEvent();
        $holiday = BlockedDate::create([
            'blockable_type' => 'event', 'blockable_uuid' => $event->uuid,
            'blocked_date' => '2026-12-25', 'reason' => 'Christmas holiday',
        ]);
        BlockedDate::create([
            'blockable_type' => 'event', 'blockable_uuid' => $event->uuid,
            'blocked_date' => '2026-12-26', 'reason' => 'Maintenance',
        ]);

        $this->assertSameModels([$holiday], BlockedDate::filters(['q' => 'Christmas'])->get());
    }
}
