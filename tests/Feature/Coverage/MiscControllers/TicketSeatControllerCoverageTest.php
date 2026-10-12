<?php

namespace Tests\Feature\Coverage\MiscControllers;

use App\Exceptions\NoTicketSeatFoundException;
use App\Http\Repositories\TicketSeatRepository;
use App\Models\Ticket;
use App\Models\TicketSeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TicketSeatControllerCoverageTest extends TestCase
{
    use MiscFixtures;
    use RefreshDatabase;

    private Ticket $ticket;

    private TicketSeat $ticketSeat;

    protected function setUp(): void
    {
        parent::setUp();

        $event = $this->createEvent();
        $this->ticket = $this->createTicket($this->createTransaction($this->createCustomer(), $event));
        $venue = $this->createVenue();
        $this->ticketSeat = TicketSeat::create([
            'ticket_uuid' => $this->ticket->uuid,
            'venue_uuid' => $venue->uuid,
            'venue_seat_uuid' => $this->createVenueSeat($venue)->uuid,
            'col' => 'A',
            'row' => 1,
            'seat_no' => 1,
            'category' => 'gold',
            'color' => 'gold',
            'status' => 'active',
        ]);
    }

    #[Test]
    public function showReturnsTicketSeat(): void
    {
        $this->withHeaders($this->adminHeaders())
            ->getJson("/api/v1/ticket-seats/{$this->ticketSeat->uuid}")
            ->assertOk()
            ->assertJsonPath('data.uuid', $this->ticketSeat->uuid)
            ->assertJsonPath('data.ticket_uuid', $this->ticket->uuid);
    }

    #[Test]
    public function showReturns404ForUnknownTicketSeat(): void
    {
        $this->withHeaders($this->adminHeaders())
            ->getJson('/api/v1/ticket-seats/00000000-0000-0000-0000-000000000000')
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Ticket seat not found']);
    }

    #[Test]
    public function updateChangesTicketSeat(): void
    {
        $this->withHeaders($this->adminHeaders())
            ->putJson("/api/v1/ticket-seats/{$this->ticketSeat->uuid}", [
                'col' => 'B',
                'seat_no' => 7,
                'category' => 'vip',
            ])
            ->assertOk()
            ->assertJsonPath('data.uuid', $this->ticketSeat->uuid);

        $this->assertDatabaseHas('ticket_seats', [
            'uuid' => $this->ticketSeat->uuid,
            'col' => 'B',
            'seat_no' => 7,
            'category' => 'vip',
        ]);
    }

    #[Test]
    public function updateReturns404WhenTicketSeatDisappearsAfterValidation(): void
    {
        $this->mock(TicketSeatRepository::class, function ($mock) {
            $mock->shouldReceive('fetchOrThrow')->once()->andThrow(new NoTicketSeatFoundException());
            $mock->shouldNotReceive('update');
        });

        $this->withHeaders($this->adminHeaders())
            ->putJson("/api/v1/ticket-seats/{$this->ticketSeat->uuid}", ['col' => 'C'])
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Ticket seat not found']);
    }

    #[Test]
    public function destroySoftDeletesTicketSeat(): void
    {
        $this->withHeaders($this->adminHeaders())
            ->deleteJson("/api/v1/ticket-seats/{$this->ticketSeat->uuid}")
            ->assertNoContent();

        $this->assertSoftDeleted('ticket_seats', ['uuid' => $this->ticketSeat->uuid]);
    }

    #[Test]
    public function destroyReturns404ForUnknownTicketSeat(): void
    {
        $this->withHeaders($this->adminHeaders())
            ->deleteJson('/api/v1/ticket-seats/00000000-0000-0000-0000-000000000000')
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Ticket seat not found']);
    }

    #[Test]
    public function destroyReturns403WhenTicketWasAlreadyUsed(): void
    {
        $this->ticket->update(['used_at' => now()]);

        $this->withHeaders($this->adminHeaders())
            ->deleteJson("/api/v1/ticket-seats/{$this->ticketSeat->uuid}")
            ->assertForbidden()
            ->assertJson(['success' => false, 'message' => 'Cannot delete seat from a used ticket.']);

        $this->assertDatabaseHas('ticket_seats', ['uuid' => $this->ticketSeat->uuid, 'deleted_at' => null]);
    }
}
