<?php

namespace Tests\Feature\Coverage\GapTickets;

use App\Constants\GeneralConstants;
use App\Exceptions\UnauthorizedException;
use App\Http\Repositories\TicketRepository;
use App\Jobs\RecordAffiliateCommissionReversalForCancelledTicketJob;
use App\Models\Event;
use App\Models\EventTicket;
use App\Models\EventTicketCoupon;
use App\Models\Ticket;
use App\Models\TicketCoupon;
use App\Models\TicketSeat;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TicketRepositoryUpgradeCancelTest extends TestCase
{
    use GapTicketsFixtures;
    use RefreshDatabase;

    private TicketRepository $repository;

    private User $customer;

    private Event $event;

    private EventTicket $source;

    private EventTicket $target;

    private Transaction $transaction;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake();
        $this->repository = app(TicketRepository::class);
        $this->actAsRequestUser($this->createAdmin());
        $this->customer = $this->createCustomer();
        $this->event = $this->createEvent(['ticket_sold' => 1]);
        $this->source = $this->createEventTicket($this->event, ['name' => 'General', 'price' => 100]);
        $this->target = $this->createEventTicket($this->event, ['name' => 'VIP', 'price' => 200, 'sold_ticket' => 0]);
        $this->transaction = $this->createTransaction($this->customer, $this->event);
    }

    private function ticket(array $attributes = []): Ticket
    {
        return $this->createTicket($this->transaction, $this->source, $attributes);
    }

    private function assertUpgradeRejected(Ticket $ticket, EventTicket $target, string $message): void
    {
        try {
            $this->repository->upgrade($ticket, $target, 200);
            $this->fail('Expected UnauthorizedException');
        } catch (UnauthorizedException $e) {
            $this->assertSame($message, $e->getMessage());
        }

        $this->assertSame(0, Transaction::where('payment_provider', 'upgrade')->count());
    }

    #[Test]
    public function upgradeRejectsNonActiveTicket(): void
    {
        $ticket = $this->ticket(['status' => GeneralConstants::TICKET_STATUSES['PENDING']]);

        $this->assertUpgradeRejected($ticket, $this->target, 'Only active tickets can be upgraded.');
    }

    #[Test]
    public function upgradeRejectsTargetFromAnotherEvent(): void
    {
        $foreign = $this->createEventTicket($this->createEvent());

        $this->assertUpgradeRejected($this->ticket(), $foreign, 'Upgrade target must belong to the same event.');
    }

    #[Test]
    public function upgradeRejectsSameTicketType(): void
    {
        $this->assertUpgradeRejected($this->ticket(), $this->source, 'Choose a different ticket type to upgrade to.');
    }

    #[Test]
    public function upgradeRejectsInactiveTargetType(): void
    {
        $this->target->update(['status' => GeneralConstants::GENERAL_STATUSES['INACTIVE']]);

        $this->assertUpgradeRejected($this->ticket(), $this->target->fresh(), 'The selected ticket type is not active.');
    }

    #[Test]
    public function upgradeRejectsBundleTargetType(): void
    {
        $this->target->update(['is_bundle' => true]);

        $this->assertUpgradeRejected($this->ticket(), $this->target->fresh(), 'Bundle ticket types cannot be used for upgrades.');
    }

    #[Test]
    public function upgradeRejectsSoldOutTargetType(): void
    {
        $this->target->update(['is_unlimited' => false, 'max_ticket' => 2, 'sold_ticket' => 2]);

        $this->assertUpgradeRejected($this->ticket(), $this->target->fresh(), 'The selected ticket type has no remaining inventory.');
    }

    #[Test]
    public function upgradeRejectsTicketWithoutTransaction(): void
    {
        $ticket = $this->ticket();
        $this->transaction->delete();

        $this->assertUpgradeRejected($ticket, $this->target, 'Ticket has no transaction.');
    }

    #[Test]
    public function upgradeRollsBackWhenTicketHasNoMatchingOrderLine(): void
    {
        $ticket = $this->ticket();

        try {
            $this->repository->upgrade($ticket, $this->target, 200);
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertSame('Transaction order not found for this ticket.', $e->getMessage());
        }

        $this->assertSame(GeneralConstants::TICKET_STATUSES['ACTIVE'], $ticket->fresh()->status);
        $this->assertSame(0, Transaction::where('payment_provider', 'upgrade')->count());
    }

    #[Test]
    public function upgradeRollsBackWhenOrderTotalsAreZero(): void
    {
        $this->createOrder($this->transaction, $this->source, ['total_amount' => 0]);
        $ticket = $this->ticket();

        try {
            $this->repository->upgrade($ticket, $this->target, 200);
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertSame('Invalid transaction order totals.', $e->getMessage());
        }

        $this->assertSame(GeneralConstants::TICKET_STATUSES['ACTIVE'], $ticket->fresh()->status);
    }

    #[Test]
    public function upgradeReleasesSeatAndIssuesTargetCoupons(): void
    {
        $this->createOrder($this->transaction, $this->source);
        $ticket = $this->ticket();
        $seat = $this->attachTicketSeat($ticket);
        EventTicketCoupon::create(['event_ticket_uuid' => $this->target->uuid, 'name' => 'VIP Lounge']);

        $newTicket = $this->repository->upgrade($ticket, $this->target, 200);

        $this->assertSoftDeleted('ticket_seats', ['uuid' => $seat->uuid]);
        $this->assertSame(GeneralConstants::TICKET_STATUSES['CANCELLED'], $ticket->fresh()->status);
        $this->assertSame($this->target->uuid, $newTicket->event_ticket_uuid);
        $this->assertDatabaseHas('ticket_coupons', [
            'ticket_uuid' => $newTicket->uuid,
            'user_uuid' => $this->customer->uuid,
            'name' => 'VIP Lounge',
            'status' => GeneralConstants::TICKET_COUPON_STATUSES['ACTIVE'],
        ]);
        $this->assertStringStartsWith('COUPON_', TicketCoupon::where('ticket_uuid', $newTicket->uuid)->value('qr_code'));
    }

    #[Test]
    public function cancelRollsBackWhenTicketHasNoMatchingOrderLine(): void
    {
        $ticket = $this->ticket();

        try {
            $this->repository->cancel($ticket, 'No order');
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertSame('Transaction order not found for this ticket.', $e->getMessage());
        }

        $this->assertSame(GeneralConstants::TICKET_STATUSES['ACTIVE'], $ticket->fresh()->status);
        $this->assertSame(0, Transaction::where('payment_provider', 'refund')->count());
        Bus::assertNotDispatched(RecordAffiliateCommissionReversalForCancelledTicketJob::class);
    }

    #[Test]
    public function cancelReleasesTicketSeat(): void
    {
        $this->createOrder($this->transaction, $this->source);
        $ticket = $this->ticket();
        $seat = $this->attachTicketSeat($ticket);

        $this->assertTrue($this->repository->cancel($ticket, 'Customer request'));

        $this->assertSoftDeleted('ticket_seats', ['uuid' => $seat->uuid]);
        $this->assertSame(0, TicketSeat::where('ticket_uuid', $ticket->uuid)->count());
        $this->assertSame(GeneralConstants::TICKET_STATUSES['CANCELLED'], $ticket->fresh()->status);
        Bus::assertDispatched(RecordAffiliateCommissionReversalForCancelledTicketJob::class);
    }
}
