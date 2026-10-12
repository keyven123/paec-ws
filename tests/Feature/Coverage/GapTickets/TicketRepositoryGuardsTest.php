<?php

namespace Tests\Feature\Coverage\GapTickets;

use App\Constants\GeneralConstants;
use App\Exceptions\NoUserFoundException;
use App\Exceptions\UnauthorizedException;
use App\Http\Repositories\TicketRepository;
use App\Models\Event;
use App\Models\EventTicket;
use App\Models\Ticket;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TicketRepositoryGuardsTest extends TestCase
{
    use GapTicketsFixtures;
    use RefreshDatabase;

    private TicketRepository $repository;

    private User $customer;

    private Event $event;

    private EventTicket $eventTicket;

    private Transaction $transaction;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = app(TicketRepository::class);
        $this->customer = $this->createCustomer();
        $this->event = $this->createEvent();
        $this->eventTicket = $this->createEventTicket($this->event);
        $this->transaction = $this->createTransaction($this->customer, $this->event);
    }

    private function ticket(array $attributes = []): Ticket
    {
        return $this->createTicket($this->transaction, $this->eventTicket, $attributes);
    }

    #[Test]
    public function deleteRejectsTicketWithAssociatedSeat(): void
    {
        $ticket = $this->ticket();
        $this->attachTicketSeat($ticket);

        try {
            $this->repository->delete($ticket);
            $this->fail('Expected UnauthorizedException');
        } catch (UnauthorizedException $e) {
            $this->assertSame('Cannot delete ticket with associated ticket seats.', $e->getMessage());
        }

        $this->assertNotSoftDeleted('tickets', ['uuid' => $ticket->uuid]);
    }

    #[Test]
    public function markAsUsedRejectsInactiveTicket(): void
    {
        $ticket = $this->ticket(['status' => GeneralConstants::TICKET_STATUSES['PENDING']]);

        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage('Only active tickets can be used.');

        $this->repository->markAsUsed($ticket);
    }

    #[Test]
    public function markAsUsedForCustomerRejectsAlreadyUsedTicket(): void
    {
        $ticket = $this->ticket(['used_at' => now()]);

        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage('Ticket has already been used.');

        $this->repository->markAsUsedForCustomer($ticket);
    }

    #[Test]
    public function markAsUsedForCustomerRejectsInactiveTicket(): void
    {
        $ticket = $this->ticket(['status' => GeneralConstants::TICKET_STATUSES['EXPIRED']]);

        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage('Only active tickets can be used.');

        $this->repository->markAsUsedForCustomer($ticket);
    }

    #[Test]
    public function transferRejectsInactiveTicket(): void
    {
        $ticket = $this->ticket(['status' => GeneralConstants::TICKET_STATUSES['CANCELLED']]);

        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage('Only active tickets can be transferred.');

        $this->repository->transferTicket($ticket, $this->createCustomer());
    }

    #[Test]
    public function transferByEmailRejectsUnknownEmail(): void
    {
        $this->expectException(NoUserFoundException::class);

        $this->repository->transferTicketByEmail($this->ticket(), 'nobody@test.com', $this->customer->uuid);
    }

    #[Test]
    public function transferByEmailRejectsInactiveRecipient(): void
    {
        $recipient = $this->createCustomer(['status' => GeneralConstants::GENERAL_STATUSES['INACTIVE']]);

        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage('The selected user is not active.');

        $this->repository->transferTicketByEmail($this->ticket(), $recipient->email, $this->customer->uuid);
    }

    #[Test]
    public function transferByEmailRejectsSelfTransfer(): void
    {
        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage('You cannot transfer a ticket to yourself.');

        $this->repository->transferTicketByEmail($this->ticket(), $this->customer->email, $this->customer->uuid);
    }

    #[Test]
    public function confirmEntryRejectsInactiveTicket(): void
    {
        $ticket = $this->ticket(['status' => GeneralConstants::TICKET_STATUSES['PENDING']]);

        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage('Only active tickets can be confirmed for entry.');

        $this->repository->confirmEntry($ticket);
    }

    #[Test]
    public function confirmOrderEntrySkipsUsedAndInactiveTickets(): void
    {
        $active = $this->ticket();
        $used = $this->ticket(['used_at' => now()->subHour(), 'status' => GeneralConstants::TICKET_STATUSES['USED']]);
        $cancelled = $this->ticket(['status' => GeneralConstants::TICKET_STATUSES['CANCELLED']]);

        $admitted = $this->repository->confirmOrderEntry($this->transaction->fresh());

        $this->assertSame(1, $admitted);
        $this->assertSame(GeneralConstants::TICKET_STATUSES['USED'], $active->fresh()->status);
        $this->assertNotNull($active->fresh()->used_at);
        $this->assertSame(GeneralConstants::TICKET_STATUSES['CANCELLED'], $cancelled->fresh()->status);
        $this->assertEquals($used->used_at->timestamp, $used->fresh()->used_at->timestamp);
        $this->assertSame(1, (int) $this->event->fresh()->registration_count);
    }
}
