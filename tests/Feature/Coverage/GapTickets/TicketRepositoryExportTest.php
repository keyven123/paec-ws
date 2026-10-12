<?php

namespace Tests\Feature\Coverage\GapTickets;

use App\Constants\GeneralConstants;
use App\Http\Repositories\TicketRepository;
use App\Models\Event;
use App\Models\EventTicket;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TicketRepositoryExportTest extends TestCase
{
    use GapTicketsFixtures;
    use RefreshDatabase;

    private TicketRepository $repository;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = app(TicketRepository::class);
        $this->customer = $this->createCustomer(['first_name' => 'Jane', 'last_name' => 'Buyer']);
    }

    /**
     * @return list<list<string>>
     */
    private function parseCsv(string $csv): array
    {
        $lines = array_values(array_filter(explode("\n", $csv), fn ($line) => $line !== ''));

        return array_map(fn ($line) => explode('","', substr($line, 1, -1)), $lines);
    }

    private function seedEvent(array $attributes = []): array
    {
        $event = $this->createEvent($attributes);
        $eventTicket = $this->createEventTicket($event, ['name' => 'VIP Pass']);
        $transaction = $this->createTransaction($this->customer, $event);

        return [$event, $eventTicket, $transaction];
    }

    #[Test]
    public function exportUsedTicketsAppendsOtherInfoColumns(): void
    {
        /** @var Event $event */
        /** @var EventTicket $eventTicket */
        /** @var Transaction $transaction */
        [$event, $eventTicket, $transaction] = $this->seedEvent([
            'other_info' => ['company' => 'text', 'shirt' => 'text'],
        ]);
        $this->createTicket($transaction, $eventTicket, [
            'ticket_number' => 'TN-ANSWERED',
            'status' => GeneralConstants::TICKET_STATUSES['USED'],
            'used_at' => '2026-05-01 09:30:00',
            'col' => 2,
            'row' => 4,
            'other_info' => ['company' => 'Acme', 'shirt' => null],
        ]);
        $this->createTicket($transaction, $eventTicket, [
            'ticket_number' => 'TN-BLANK',
            'status' => GeneralConstants::TICKET_STATUSES['USED'],
            'other_info' => null,
        ]);
        $this->createTicket($transaction, $eventTicket, ['ticket_number' => 'TN-ACTIVE']);

        $rows = $this->parseCsv($this->repository->exportUsedTickets($event));

        $this->assertSame(['company', 'shirt'], array_slice($rows[0], -2));
        $this->assertCount(3, $rows);

        $byNumber = collect(array_slice($rows, 1))->keyBy(0);
        $this->assertFalse($byNumber->has('TN-ACTIVE'));

        $answered = $byNumber['TN-ANSWERED'];
        $this->assertSame('VIP Pass', $answered[1]);
        $this->assertSame($this->customer->full_name, $answered[2]);
        $this->assertSame('2-4', $answered[6]);
        $this->assertSame('2026/05/01 09:30:00', $answered[7]);
        $this->assertSame(['Acme', ''], array_slice($answered, -2));

        $blank = $byNumber['TN-BLANK'];
        $this->assertSame('N/A', $blank[7]);
        $this->assertSame(['', ''], array_slice($blank, -2));
    }

    #[Test]
    public function exportOccupiedSeatsListsPaidSeatsWithAttendees(): void
    {
        $venue = $this->createVenue();
        [$event, $eventTicket, $transaction] = $this->seedEvent(['venue_uuid' => $venue->uuid]);
        $seat = $this->createVenueSeat($venue, ['col' => 5, 'row' => 'B', 'seat_no' => 12]);
        $this->createTicket($transaction, $eventTicket, [
            'venue_seat_uuid' => $seat->uuid,
            'attendee_name' => 'Seated Guest',
        ]);

        $rows = $this->parseCsv($this->repository->exportOccupiedSeats($event));

        $this->assertSame(['Event Name', 'Venue', 'Row', 'Column', 'Seat No', 'Attendee Name', 'Status'], $rows[0]);
        $this->assertSame(
            [$event->event_name, $venue->name, '5', 'B', '12', 'Seated Guest', 'Occupied'],
            $rows[1],
        );
        $this->assertCount(2, $rows);
    }

    #[Test]
    public function exportTicketsIncludesSeatUsageAndTransferDetails(): void
    {
        [$event, $eventTicket, $transaction] = $this->seedEvent();
        $recipient = $this->createCustomer(['first_name' => 'Rick', 'last_name' => 'Recipient']);
        $sender = $this->createCustomer(['first_name' => 'Sam', 'last_name' => 'Sender']);

        $this->travelTo(now()->subMinutes(3));
        $this->createTicket($transaction, $eventTicket, [
            'ticket_number' => 'TN-1',
            'qr_code' => 'QR-1',
            'col' => 3,
            'row' => 9,
            'status' => GeneralConstants::TICKET_STATUSES['TRANSFERRED'],
            'used_at' => '2026-04-01 08:00:00',
            'transferred_at' => '2026-03-01 10:15:00',
            'transferred_to' => $recipient->uuid,
            'transferred_by' => $sender->uuid,
            'transfer_count' => 1,
            'type' => 'paid',
            'remarks' => 'Moved',
        ]);
        $this->travel(1)->minutes();
        $this->createTicket($transaction, $eventTicket, [
            'ticket_number' => 'TN-2',
            'qr_code' => 'QR-2',
            'transfer_count' => 2,
        ]);
        $this->travel(1)->minutes();
        $this->createTicket($transaction, $eventTicket, [
            'ticket_number' => 'TN-3',
            'qr_code' => 'QR-3',
        ]);
        $this->travelBack();

        $rows = $this->parseCsv($this->repository->exportTickets($event));

        $this->assertCount(4, $rows);
        $this->assertSame('Transferred By', $rows[0][12]);

        $this->assertSame([
            '1', $event->event_name, 'TN-1', 'QR-1', '3-9', 'transferred', 'Attendee', 'attendee@test.com',
            '2026/04/01 08:00:00', '2026/03/01 10:15:00', $recipient->full_name, $recipient->email,
            $sender->full_name, 'paid', 'Moved',
        ], $rows[1]);

        $this->assertSame('2', $rows[2][0]);
        $this->assertSame('', $rows[2][4]);
        $this->assertSame('N/A', $rows[2][8]);
        $this->assertSame('N/A', $rows[2][9]);
        $this->assertSame($this->customer->full_name, $rows[2][12]);

        $this->assertSame('TN-3', $rows[3][2]);
        $this->assertSame('', $rows[3][12]);
    }
}
