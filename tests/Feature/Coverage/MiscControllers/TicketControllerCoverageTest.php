<?php

namespace Tests\Feature\Coverage\MiscControllers;

use App\Constants\GeneralConstants;
use App\Exceptions\NoTicketFoundException;
use App\Http\Repositories\TicketRepository;
use App\Models\Event;
use App\Models\Ticket;
use App\Models\Transaction;
use App\Models\TransactionOrder;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TicketControllerCoverageTest extends TestCase
{
    use MiscFixtures;
    use RefreshDatabase;

    private const MISSING_UUID = '00000000-0000-0000-0000-000000000000';

    private User $customer;

    private Event $event;

    private Transaction $transaction;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = $this->createCustomer(['first_name' => 'Ana', 'last_name' => 'Reyes']);
        $this->event = $this->createEvent();
        $this->transaction = $this->createTransaction($this->customer, $this->event);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function ticket(array $attributes = []): Ticket
    {
        return $this->createTicket($this->transaction, array_merge([
            'qr_code' => 'QR-' . uniqid(),
            'visit_policy' => 'priority',
        ], $attributes));
    }

    private function scan(Ticket $ticket): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders($this->adminHeaders())
            ->getJson('/api/v1/tickets/qr-code/details?qr_code=' . urlencode($ticket->qr_code));
    }

    private function freezeAt(string $dateTime): Carbon
    {
        $now = Carbon::parse($dateTime, (string) config('app.timezone', 'UTC'));
        Carbon::setTestNow($now);

        return $now;
    }

    #[Test]
    public function showReturnsTicket(): void
    {
        $ticket = $this->ticket();

        $this->withHeaders($this->adminHeaders())
            ->getJson("/api/v1/tickets/{$ticket->uuid}")
            ->assertOk()
            ->assertJsonPath('data.uuid', $ticket->uuid);
    }

    #[Test]
    public function updateChangesAttendeeDetails(): void
    {
        $ticket = $this->ticket();

        $this->withHeaders($this->adminHeaders())
            ->putJson("/api/v1/tickets/{$ticket->uuid}", ['attendee_name' => 'New Attendee'])
            ->assertOk()
            ->assertJsonPath('data.uuid', $ticket->uuid);

        $this->assertSame('New Attendee', $ticket->fresh()->attendee_name);
    }

    #[Test]
    public function updateReturns404WhenTicketDisappearsAfterValidation(): void
    {
        $ticket = $this->ticket();

        $this->mock(TicketRepository::class, function ($mock) {
            $mock->shouldReceive('fetchOrThrow')->once()->andThrow(new NoTicketFoundException());
            $mock->shouldNotReceive('update');
        });

        $this->withHeaders($this->adminHeaders())
            ->putJson("/api/v1/tickets/{$ticket->uuid}", ['attendee_name' => 'Nobody'])
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Ticket not found']);
    }

    #[Test]
    public function destroySoftDeletesUnusedTicket(): void
    {
        $ticket = $this->ticket();

        $this->withHeaders($this->adminHeaders())
            ->deleteJson("/api/v1/tickets/{$ticket->uuid}")
            ->assertNoContent();

        $this->assertSoftDeleted('tickets', ['uuid' => $ticket->uuid]);
    }

    #[Test]
    public function destroyReturns404ForUnknownTicket(): void
    {
        $this->withHeaders($this->adminHeaders())
            ->deleteJson('/api/v1/tickets/' . self::MISSING_UUID)
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Ticket not found']);
    }

    #[Test]
    public function destroyRefusesUsedTicket(): void
    {
        $ticket = $this->ticket(['used_at' => now()]);

        $this->withHeaders($this->adminHeaders())
            ->deleteJson("/api/v1/tickets/{$ticket->uuid}")
            ->assertForbidden()
            ->assertExactJson(['success' => false, 'message' => 'Cannot delete a used ticket.']);

        $this->assertDatabaseHas('tickets', ['uuid' => $ticket->uuid, 'deleted_at' => null]);
    }

    #[Test]
    public function markAsUsedReturns404ForUnknownTicket(): void
    {
        $this->withHeaders($this->adminHeaders())
            ->postJson('/api/v1/tickets/' . self::MISSING_UUID . '/use')
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Ticket not found']);
    }

    #[Test]
    public function markAsUsedRefusesAlreadyUsedTicket(): void
    {
        $ticket = $this->ticket(['used_at' => now()]);

        $this->withHeaders($this->adminHeaders())
            ->postJson("/api/v1/tickets/{$ticket->uuid}/use")
            ->assertForbidden()
            ->assertExactJson(['success' => false, 'message' => 'Ticket has already been used.']);
    }

    #[Test]
    public function transferIssuesNewTicketToRecipient(): void
    {
        $ticket = $this->ticket();
        $recipient = $this->createCustomer();

        $this->withHeaders($this->adminHeaders())
            ->postJson("/api/v1/tickets/{$ticket->uuid}/transfer", ['user_uuid' => $recipient->uuid])
            ->assertOk()
            ->assertJsonPath('data.uuid', $ticket->uuid)
            ->assertJsonPath('data.status', GeneralConstants::TICKET_STATUSES['TRANSFERRED']);

        $ticket->refresh();
        $this->assertSame($recipient->uuid, $ticket->transferred_to);
        $this->assertSame($this->superAdmin->uuid, $ticket->transferred_by);
        $this->assertDatabaseHas('tickets', [
            'user_uuid' => $recipient->uuid,
            'transaction_uuid' => $this->transaction->uuid,
            'status' => GeneralConstants::TICKET_STATUSES['ACTIVE'],
        ]);
    }

    #[Test]
    public function transferReturns404WhenRecipientWasSoftDeleted(): void
    {
        $ticket = $this->ticket();
        $recipient = $this->createCustomer();
        $recipient->delete();

        $this->withHeaders($this->adminHeaders())
            ->postJson("/api/v1/tickets/{$ticket->uuid}/transfer", ['user_uuid' => $recipient->uuid])
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'User not found']);

        $this->assertSame(GeneralConstants::TICKET_STATUSES['ACTIVE'], $ticket->fresh()->status);
    }

    #[Test]
    public function transferReturns404ForUnknownTicket(): void
    {
        $this->withHeaders($this->adminHeaders())
            ->postJson('/api/v1/tickets/' . self::MISSING_UUID . '/transfer', ['user_uuid' => $this->customer->uuid])
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Ticket not found']);
    }

    #[Test]
    public function transferRefusesUsedTicket(): void
    {
        $ticket = $this->ticket(['used_at' => now()]);

        $this->withHeaders($this->adminHeaders())
            ->postJson("/api/v1/tickets/{$ticket->uuid}/transfer", ['user_uuid' => $this->createCustomer()->uuid])
            ->assertForbidden()
            ->assertExactJson(['success' => false, 'message' => 'Cannot transfer a used ticket.']);
    }

    #[Test]
    public function qrDetailsReturns404ForUnknownCode(): void
    {
        $this->withHeaders($this->adminHeaders())
            ->getJson('/api/v1/tickets/qr-code/details?qr_code=NOPE')
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Ticket not found with the provided QR code']);
    }

    #[Test]
    public function confirmEntrySkipsScheduleWindowWhenTransactionIsGone(): void
    {
        $ticket = $this->ticket(['used_at' => now()]);
        $this->transaction->delete();

        $this->withHeaders($this->adminHeaders())
            ->postJson("/api/v1/tickets/{$ticket->uuid}/confirm-entry")
            ->assertForbidden()
            ->assertExactJson(['success' => false, 'message' => 'Ticket has already been used.']);
    }

    #[Test]
    public function qrDetailsAllowsOvernightScheduleAfterMidnightRollover(): void
    {
        $this->freezeAt('2030-05-10 23:30:00');
        $schedule = $this->createSchedule($this->event, ['date_from' => '2030-05-10', 'date_to' => '2030-05-10']);
        $time = $this->createScheduleTime($schedule, ['time_start' => '23:00:00', 'time_end' => '01:00:00']);
        $this->transaction->update(['schedule_uuid' => $schedule->uuid, 'schedule_time_uuid' => $time->uuid]);

        $this->scan($this->ticket())->assertOk();
    }

    #[Test]
    public function qrDetailsRejectsTicketForEndedAllDaySchedule(): void
    {
        $this->freezeAt('2030-05-10 09:00:00');
        $schedule = $this->createSchedule($this->event, ['date_from' => '2030-05-08', 'date_to' => '2030-05-09']);
        $this->transaction->update(['schedule_uuid' => $schedule->uuid]);

        $response = $this->scan($this->ticket())
            ->assertForbidden()
            ->assertJsonPath('code', 'schedule_ended');

        $this->assertSame(
            Carbon::parse('2030-05-09 23:59:59', (string) config('app.timezone', 'UTC'))->toISOString(),
            $response->json('meta.schedule_end'),
        );
    }

    #[Test]
    public function qrDetailsRejectsTicketWhoseOrderVisitDateIsNotToday(): void
    {
        $this->freezeAt('2030-05-10 09:00:00');
        $ticket = $this->ticket(['attendee_name' => 'Gate Guest']);
        TransactionOrder::create([
            'user_uuid' => $this->customer->uuid,
            'transaction_uuid' => $this->transaction->uuid,
            'event_ticket_uuid' => $ticket->event_ticket_uuid,
            'quantity' => 1,
            'price' => 100,
            'total_amount' => 100,
            'valid_until' => '2030-05-12 00:00:00',
        ]);

        $this->scan($ticket)
            ->assertForbidden()
            ->assertJsonPath('code', 'visit_not_today')
            ->assertJsonPath('message', 'Your visit is not today.')
            ->assertJsonPath('meta.visit_date', '2030-05-12')
            ->assertJsonPath('meta.today', '2030-05-10')
            ->assertJsonPath('meta.attendee_name', 'Gate Guest');
    }

    #[Test]
    public function qrDetailsRejectsExpiredFlexibleTicketAndNamesTheHolder(): void
    {
        $this->freezeAt('2030-05-10 09:00:00');
        $ticket = $this->ticket([
            'visit_policy' => 'flexible',
            'valid_until' => Carbon::parse('2030-05-08 12:00:00', (string) config('app.timezone', 'UTC')),
            'attendee_name' => '',
        ]);

        $this->scan($ticket)
            ->assertForbidden()
            ->assertJsonPath('code', 'visit_expired')
            ->assertJsonPath('message', 'This ticket has expired.')
            ->assertJsonPath('meta.valid_until', '2030-05-08')
            ->assertJsonPath('meta.attendee_name', 'Ana Reyes');
    }

    #[Test]
    public function qrDetailsAllowsPriorityTicketOnItsVisitDate(): void
    {
        $this->freezeAt('2030-05-10 09:00:00');
        $ticket = $this->ticket([
            'valid_until' => Carbon::parse('2030-05-10 18:00:00', (string) config('app.timezone', 'UTC')),
        ]);

        $this->scan($ticket)
            ->assertOk()
            ->assertJsonPath('data.uuid', $ticket->uuid);
    }

    #[Test]
    public function confirmEntryReturns404ForUnknownTicket(): void
    {
        $this->withHeaders($this->adminHeaders())
            ->postJson('/api/v1/tickets/' . self::MISSING_UUID . '/confirm-entry')
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Ticket not found']);
    }

    #[Test]
    public function confirmEntryRefusesAlreadyUsedTicket(): void
    {
        $ticket = $this->ticket(['used_at' => now()]);

        $this->withHeaders($this->adminHeaders())
            ->postJson("/api/v1/tickets/{$ticket->uuid}/confirm-entry")
            ->assertForbidden()
            ->assertExactJson(['success' => false, 'message' => 'Ticket has already been used.']);
    }

    #[Test]
    public function confirmOrderEntryReturns404ForUnknownOrderCode(): void
    {
        $this->withHeaders($this->adminHeaders())
            ->postJson('/api/v1/tickets/order-qr/confirm-entry', ['qr_code' => 'NO-SUCH-ORDER'])
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Order not found with the provided QR code']);
    }

    #[Test]
    public function upgradeReturns404WhenTicketDisappearsAfterValidation(): void
    {
        $ticket = $this->ticket();
        $target = $this->createEventTicket($this->event);

        $this->mock(TicketRepository::class, function ($mock) {
            $mock->shouldReceive('fetchOrThrow')->once()->andThrow(new NoTicketFoundException());
            $mock->shouldNotReceive('upgrade');
        });

        $this->withHeaders($this->adminHeaders())
            ->putJson("/api/v1/tickets/{$ticket->uuid}/upgrade", ['ticket_uuid' => $target->uuid, 'amount' => 50])
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Ticket not found']);
    }

    #[Test]
    public function upgradeRefusesUsedTicket(): void
    {
        $ticket = $this->ticket(['used_at' => now()]);
        $target = $this->createEventTicket($this->event);

        $this->withHeaders($this->adminHeaders())
            ->putJson("/api/v1/tickets/{$ticket->uuid}/upgrade", ['ticket_uuid' => $target->uuid, 'amount' => 50])
            ->assertForbidden()
            ->assertExactJson(['success' => false, 'message' => 'Cannot upgrade a used ticket.']);
    }

    #[Test]
    public function cancelReturns404ForUnknownTicket(): void
    {
        $this->withHeaders($this->adminHeaders())
            ->putJson('/api/v1/tickets/' . self::MISSING_UUID . '/cancel', ['remarks' => 'Duplicate'])
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Ticket not found']);
    }

    #[Test]
    public function cancelRefusesTicketThatIsNotActive(): void
    {
        $ticket = $this->ticket(['status' => GeneralConstants::TICKET_STATUSES['USED']]);

        $this->withHeaders($this->adminHeaders())
            ->putJson("/api/v1/tickets/{$ticket->uuid}/cancel", ['remarks' => 'Duplicate'])
            ->assertForbidden()
            ->assertExactJson(['success' => false, 'message' => 'Only active tickets can be cancelled.']);

        $this->assertSame(GeneralConstants::TICKET_STATUSES['USED'], $ticket->fresh()->status);
    }
}
