<?php

namespace Tests\Feature\Coverage\GapTickets;

use App\Constants\GeneralConstants;
use App\Exceptions\UnauthorizedException;
use App\Http\Repositories\TicketRepository;
use App\Models\Event;
use App\Models\EventTicket;
use App\Models\EventTicketCoupon;
use App\Models\Ticket;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TicketRepositoryAddToUserTest extends TestCase
{
    use GapTicketsFixtures;
    use RefreshDatabase;

    private TicketRepository $repository;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = app(TicketRepository::class);
        $this->actAsRequestUser($this->createAdmin());
        $this->customer = $this->createCustomer();
    }

    private function payload(Event $event, EventTicket $eventTicket, array $overrides = []): array
    {
        return array_merge([
            'event_uuid' => $event->uuid,
            'event_ticket_uuid' => $eventTicket->uuid,
            'user_uuid' => $this->customer->uuid,
            'quantity' => 1,
            'type' => Ticket::TYPES['COMPLEMENTARY'],
        ], $overrides);
    }

    #[Test]
    public function seatSelectionEventRejectsMultipleTicketsAndRollsBack(): void
    {
        $event = $this->createEvent(['event_config' => Event::EVENT_CONFIGS['SEAT_SELECTION']]);
        $eventTicket = $this->createEventTicket($event);

        try {
            $this->repository->addTicketToUser($this->payload($event, $eventTicket, ['quantity' => 2]));
            $this->fail('Expected UnauthorizedException');
        } catch (UnauthorizedException $e) {
            $this->assertSame('Seat selection events can only add 1 ticket at a time.', $e->getMessage());
        }

        $this->assertSame(0, Ticket::count());
        $this->assertSame(0, Transaction::count());
    }

    #[Test]
    public function explicitValidUntilIsStoredOnOrderAndTicket(): void
    {
        $event = $this->createEvent();
        $eventTicket = $this->createEventTicket($event);

        $this->assertTrue($this->repository->addTicketToUser(
            $this->payload($event, $eventTicket, ['valid_until' => '2027-01-15'])
        ));

        $ticket = Ticket::where('user_uuid', $this->customer->uuid)->sole();
        $this->assertSame('2027-01-15 23:59:59', $ticket->valid_until->format('Y-m-d H:i:s'));
        $this->assertStringStartsWith('CMPL-', $ticket->qr_code);
        $this->assertSame(
            '2027-01-15',
            substr((string) $ticket->transaction->transactionOrders()->value('valid_until'), 0, 10)
        );
    }

    #[Test]
    public function flexibleTicketTypeDerivesValidityFromValidityDays(): void
    {
        $event = $this->createEvent();
        $eventTicket = $this->createEventTicket($event, ['visit_policy' => 'flexible', 'validity_days' => 10]);

        $this->repository->addTicketToUser($this->payload($event, $eventTicket));

        $ticket = Ticket::where('user_uuid', $this->customer->uuid)->sole();
        $this->assertSame(now()->addDays(10)->toDateString(), $ticket->valid_until->toDateString());
        $this->assertSame('flexible', $ticket->visit_policy);
    }

    #[Test]
    public function paidTicketWithSeatAndCouponsUsesGiftPrefixAndCopiesSeat(): void
    {
        $venue = $this->createVenue();
        $seat = $this->createVenueSeat($venue, ['col' => 3, 'row' => 7]);
        $event = $this->createEvent([
            'event_config' => Event::EVENT_CONFIGS['SEAT_SELECTION'],
            'venue_uuid' => $venue->uuid,
        ]);
        $eventTicket = $this->createEventTicket($event, ['price' => 0]);
        EventTicketCoupon::create(['event_ticket_uuid' => $eventTicket->uuid, 'name' => 'Free Popcorn']);

        $this->repository->addTicketToUser($this->payload($event, $eventTicket, [
            'type' => Ticket::TYPES['PAID'],
            'venue_seat_uuid' => $seat->uuid,
        ]));

        $ticket = Ticket::where('user_uuid', $this->customer->uuid)->sole();
        $this->assertStringStartsWith('GIFT-', $ticket->qr_code);
        $this->assertSame($seat->uuid, $ticket->venue_seat_uuid);
        $this->assertEquals(3, $ticket->col);
        $this->assertEquals(7, $ticket->row);
        $this->assertDatabaseHas('ticket_coupons', [
            'ticket_uuid' => $ticket->uuid,
            'user_uuid' => $this->customer->uuid,
            'event_uuid' => $event->uuid,
            'name' => 'Free Popcorn',
            'status' => GeneralConstants::TICKET_COUPON_STATUSES['ACTIVE'],
        ]);
    }

    #[Test]
    public function inactiveVenueSeatAbortsAndRollsBack(): void
    {
        $venue = $this->createVenue();
        $seat = $this->createVenueSeat($venue, ['status' => GeneralConstants::GENERAL_STATUSES['INACTIVE']]);
        $event = $this->createEvent(['venue_uuid' => $venue->uuid]);
        $eventTicket = $this->createEventTicket($event);

        $this->expectException(ModelNotFoundException::class);

        try {
            $this->repository->addTicketToUser($this->payload($event, $eventTicket, [
                'venue_seat_uuid' => $seat->uuid,
            ]));
        } finally {
            $this->assertSame(0, Transaction::count());
        }
    }

    #[Test]
    public function otherInfoTemplateIsFilledForEveryTicket(): void
    {
        $event = $this->createEvent(['other_info' => ['company' => 'text', 'shirt_size' => 'text']]);
        $eventTicket = $this->createEventTicket($event);

        $this->repository->addTicketToUser($this->payload($event, $eventTicket, [
            'quantity' => 2,
            'other_info' => [['company' => 'Acme', 'shirt_size' => 'M']],
        ]));

        $tickets = Ticket::where('user_uuid', $this->customer->uuid)->orderBy('qr_code')->get();
        $this->assertCount(2, $tickets);
        $otherInfos = $tickets->pluck('other_info')->all();
        $this->assertContains(['company' => 'Acme', 'shirt_size' => 'M'], $otherInfos);
        $this->assertContains(['company' => '', 'shirt_size' => ''], $otherInfos);
        $this->assertTrue(Str::startsWith($tickets->first()->qr_code, 'CMPL-'));
    }
}
