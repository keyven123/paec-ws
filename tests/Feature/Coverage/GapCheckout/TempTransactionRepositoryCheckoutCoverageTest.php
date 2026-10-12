<?php

namespace Tests\Feature\Coverage\GapCheckout;

use App\Constants\GeneralConstants;
use App\Http\Repositories\TempTransactionRepository;
use App\Jobs\SuccessPaymentProcess;
use App\Models\Event;
use App\Models\EventTicketCoupon;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Venue;
use App\Models\VenueSeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TempTransactionRepositoryCheckoutCoverageTest extends TestCase
{
    use RefreshDatabase;
    use GapCheckoutFixtures;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Bus::fake([SuccessPaymentProcess::class]);
        $this->useEmptyCheckoutHandlerRegistry();

        $this->customer = $this->createCustomer(['phone_number' => '09171112222']);
    }

    private function placeOrder(string $tempUuid, array $otherInfo = [])
    {
        return $this->actingAs($this->customer, 'api')->postJson('/api/v1/customer/temp-transactions/place-order', array_filter([
            'temp_transaction_uuid' => $tempUuid,
            'other_info' => $otherInfo,
        ]));
    }

    private function createSeat(Venue $venue, string $row, int $seatNo): VenueSeat
    {
        return VenueSeat::create([
            'venue_uuid' => $venue->uuid,
            'col' => $seatNo,
            'row' => $row,
            'seat_no' => $seatNo,
            'category' => 'gold',
            'color' => 'gold',
            'status' => 'active',
        ]);
    }

    #[Test]
    public function seatOrdersIssueOneTicketPerSeatWithAttendeeOverridesAndCoupons(): void
    {
        $venue = Venue::create(['name' => 'Gap Hall', 'code' => 'gap-hall', 'type' => Venue::TYPES[array_key_first(Venue::TYPES)]]);
        $event = $this->createEvent([
            'event_config' => Event::EVENT_CONFIGS['SEAT_SELECTION'],
            'venue_uuid' => $venue->uuid,
            'ticket_prefix' => 'GAP',
        ]);
        $eventTicket = $this->createEventTicket($event, ['visit_policy' => 'priority']);
        EventTicketCoupon::create(['event_ticket_uuid' => $eventTicket->uuid, 'name' => 'Free drink', 'once_only' => false]);
        EventTicketCoupon::create(['event_ticket_uuid' => $eventTicket->uuid, 'name' => 'Souvenir', 'once_only' => true]);
        $seatA1 = $this->createSeat($venue, 'A', 1);
        $seatA2 = $this->createSeat($venue, 'A', 2);
        $visitDate = now()->addDays(4)->startOfDay();

        $temp = $this->createTempTransaction($this->customer, $eventTicket, ['total_amount' => 900, 'sub_total' => 1000, 'discount' => 100], [
            'quantity' => 2,
            'discount' => 100,
            'total_amount' => 900,
            'valid_until' => $visitDate,
            'seats' => [
                ['uuid' => $seatA1->uuid, 'row' => 'A', 'seat_no' => 1, 'category' => 'gold'],
                ['uuid' => $seatA2->uuid, 'row' => 'A', 'seat_no' => 2, 'category' => 'gold'],
            ],
        ]);

        $response = $this->placeOrder($temp->uuid, [
            ['attendee_name' => 'Ana Seat', 'attendee_email' => 'ana@test.com', 'attendee_contact' => '09990001111', 'shirt' => 'M'],
        ])->assertCreated();

        $tickets = Ticket::with('coupons')
            ->where('transaction_uuid', $response->json('transaction.uuid'))
            ->orderBy('col')
            ->get();
        $this->assertCount(2, $tickets);

        [$first, $second] = [$tickets[0], $tickets[1]];
        $this->assertSame($seatA1->uuid, $first->venue_seat_uuid);
        $this->assertSame('A', $first->row);
        $this->assertEquals(1, $first->col);
        $this->assertSame('Ana Seat', $first->attendee_name);
        $this->assertSame('ana@test.com', $first->attendee_email);
        $this->assertSame('09990001111', $first->attendee_contact);
        $this->assertSame('M', $first->other_info['shirt']);
        $this->assertSame(GeneralConstants::TICKET_STATUSES['PENDING'], $first->status);
        $this->assertEquals(450, $first->price);
        $this->assertEquals(50, $first->discount);
        $this->assertSame($visitDate->copy()->endOfDay()->toDateTimeString(), $first->valid_until->toDateTimeString());
        $this->assertStringStartsWith('GAP', $first->ticket_number);

        $this->assertSame($seatA2->uuid, $second->venue_seat_uuid);
        $this->assertSame($this->customer->full_name, $second->attendee_name);
        $this->assertSame($this->customer->email, $second->attendee_email);
        $this->assertNull($second->other_info);

        // Only the repeatable coupon is issued; the once-only coupon at index > 0 is skipped.
        foreach ($tickets as $ticket) {
            $this->assertSame(['Free drink'], $ticket->coupons->pluck('name')->all());
        }
    }

    #[Test]
    public function flexibleTicketsWithoutAVisitDateExpireAfterTheirValidityWindow(): void
    {
        $this->freezeTime();
        $event = $this->createEvent();
        $eventTicket = $this->createEventTicket($event, ['visit_policy' => 'flexible', 'validity_days' => 3]);
        $temp = $this->createTempTransaction($this->customer, $eventTicket);

        $response = $this->placeOrder($temp->uuid)->assertCreated();

        $ticket = Ticket::where('transaction_uuid', $response->json('transaction.uuid'))->sole();
        $this->assertSame(now()->addDays(3)->endOfDay()->toDateTimeString(), $ticket->valid_until->toDateTimeString());
        $this->assertSame('flexible', $ticket->visit_policy);
    }

    #[Test]
    public function generalAdmissionTicketsMoveAttendeeDetailsOutOfOtherInfo(): void
    {
        $event = $this->createEvent();
        $eventTicket = $this->createEventTicket($event);
        $temp = $this->createTempTransaction($this->customer, $eventTicket, [], ['quantity' => 2, 'total_amount' => 1000]);

        $response = $this->placeOrder($temp->uuid, [
            ['attendee_name' => 'Ben Guest', 'attendee_email' => 'ben@test.com', 'attendee_contact' => '09181234567', 'diet' => 'vegan'],
        ])->assertCreated();

        $tickets = Ticket::where('transaction_uuid', $response->json('transaction.uuid'))->get();
        $this->assertCount(2, $tickets);

        $named = $tickets->firstWhere('attendee_name', 'Ben Guest');
        $this->assertNotNull($named);
        $this->assertSame('ben@test.com', $named->attendee_email);
        $this->assertSame('09181234567', $named->attendee_contact);
        $this->assertSame('vegan', $named->other_info['diet']);
        $this->assertArrayNotHasKey('attendee_name', $named->other_info);

        $default = $tickets->firstWhere('uuid', '!=', $named->uuid);
        $this->assertSame($this->customer->full_name, $default->attendee_name);
        $this->assertSame('09171112222', $default->attendee_contact);
    }

    #[Test]
    public function syncingTotalsForAHoldWhoseEventWasDeletedKeepsTheStoredTaxAndTotal(): void
    {
        $event = $this->createEvent();
        $eventTicket = $this->createEventTicket($event, ['price' => 200]);
        $temp = $this->createTempTransaction($this->customer, $eventTicket, [
            'sub_total' => 999,
            'tax_amount' => 12.5,
            'total_amount' => 777,
        ], ['quantity' => 2, 'total_amount' => 400]);
        $event->delete();

        $synced = app(TempTransactionRepository::class)->syncPricingTotals($temp->fresh(), 15);

        $this->assertNull($synced->event);
        $this->assertEquals(400, $synced->sub_total);
        $this->assertEquals(15, $synced->promo_code_discount);
        $this->assertEquals(12.5, $synced->tax_amount);
        $this->assertEquals(777, $synced->total_amount);
    }
}
