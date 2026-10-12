<?php

namespace Tests\Feature\Coverage\Requests;

use App\Constants\GeneralConstants;
use App\Http\Requests\ScheduleTime\UpdateScheduleTimeRequest;
use App\Http\Requests\Ticket\AddTicketToUserRequest;
use App\Http\Requests\Ticket\UpdateTicketRequest;
use App\Http\Requests\Ticket\UpgradeTicketRequest;
use App\Http\Requests\TicketSeat\UpdateTicketSeatRequest;
use App\Http\Requests\Transaction\UpdateTransactionRequest;
use App\Models\Event;
use App\Models\EventTicket;
use App\Models\Ticket;
use App\Models\TicketSeat;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TicketRequestsCoverageTest extends TestCase
{
    use RefreshDatabase;
    use RequestFixtures;

    private const UNKNOWN_UUID = '00000000-0000-4000-8000-000000000000';

    private Event $event;

    private EventTicket $eventTicket;

    private Transaction $transaction;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        $this->event = $this->makeEvent();
        $this->eventTicket = $this->makeEventTicket($this->event);
        $this->transaction = $this->makeTransaction($this->makeCustomer(), $this->event);
        $this->ticket = $this->makeTicket($this->transaction, $this->eventTicket);
    }

    #[Test]
    public function addTicketSkipsTheVisitDateCheckWithoutAnEventTicket(): void
    {
        $errors = $this->validateFormRequest(AddTicketToUserRequest::class, [
            'event_uuid' => $this->event->uuid,
            'user_uuid' => $this->transaction->user_uuid,
            'type' => Ticket::TYPES['COMPLEMENTARY'],
            'quantity' => 1,
        ]);

        $this->assertSame(['event_ticket_uuid'], array_keys($errors));
    }

    #[Test]
    public function addTicketRequiresAVisitDateForNonFlexibleTickets(): void
    {
        $priority = $this->makeEventTicket($this->event, ['visit_policy' => 'priority']);

        $errors = $this->validateFormRequest(AddTicketToUserRequest::class, [
            'event_uuid' => $this->event->uuid,
            'user_uuid' => $this->transaction->user_uuid,
            'event_ticket_uuid' => $priority->uuid,
            'type' => Ticket::TYPES['COMPLEMENTARY'],
            'quantity' => 1,
        ]);

        $this->assertSame(['valid_until' => ['Date of visit is required for this ticket.']], $errors);
    }

    #[Test]
    public function addTicketAcceptsADatedPriorityTicket(): void
    {
        $priority = $this->makeEventTicket($this->event, ['visit_policy' => 'priority']);

        $request = $this->assertFormRequestPasses(AddTicketToUserRequest::class, [
            'event_uuid' => $this->event->uuid,
            'user_uuid' => $this->transaction->user_uuid,
            'event_ticket_uuid' => $priority->uuid,
            'type' => Ticket::TYPES['PAID_NR'],
            'quantity' => 2,
            'amount' => 200,
            'valid_until' => now()->addDay()->toDateString(),
        ]);

        $this->assertSame(200, $request->validated('amount'));
    }

    #[Test]
    public function updateTicketRejectsUnknownRelationsAndDuplicateQrCodes(): void
    {
        $other = $this->makeTicket($this->transaction, $this->eventTicket);

        $errors = $this->validateFormRequest(UpdateTicketRequest::class, [
            'user_uuid' => self::UNKNOWN_UUID,
            'transaction_uuid' => self::UNKNOWN_UUID,
            'event_uuid' => self::UNKNOWN_UUID,
            'event_ticket_uuid' => self::UNKNOWN_UUID,
            'qr_code' => $other->qr_code,
        ], ['uuid' => $this->ticket->uuid]);

        $this->assertSame([
            'user_uuid' => ['The selected user does not exist.'],
            'transaction_uuid' => ['The selected transaction does not exist.'],
            'event_uuid' => ['The selected event does not exist.'],
            'event_ticket_uuid' => ['The selected event ticket does not exist.'],
            'qr_code' => ['This QR code is already in use.'],
        ], $errors);
    }

    #[Test]
    public function updateTicketAcceptsItsOwnQrCode(): void
    {
        $request = $this->assertFormRequestPasses(UpdateTicketRequest::class, [
            'qr_code' => $this->ticket->qr_code,
            'attendee_name' => 'Renamed',
            'attendee_email' => 'renamed@test.com',
            'is_virtual' => false,
        ], ['uuid' => $this->ticket->uuid]);

        $this->assertSame($this->ticket->uuid, $request->validated('uuid'));
        $this->assertSame('Renamed', $request->validated('attendee_name'));
    }

    #[Test]
    public function upgradeSkipsBusinessChecksForAnUnknownTicket(): void
    {
        $errors = $this->validateFormRequest(UpgradeTicketRequest::class, [
            'ticket_uuid' => $this->eventTicket->uuid,
            'amount' => 0,
        ], ['uuid' => self::UNKNOWN_UUID]);

        $this->assertSame(['uuid'], array_keys($errors));
    }

    #[Test]
    public function upgradeSkipsBusinessChecksForAnUnknownTicketType(): void
    {
        $errors = $this->validateFormRequest(UpgradeTicketRequest::class, [
            'ticket_uuid' => self::UNKNOWN_UUID,
            'amount' => 0,
        ], ['uuid' => $this->ticket->uuid]);

        $this->assertSame(1, count($errors['ticket_uuid']));
        $this->assertSame(['ticket_uuid'], array_keys($errors));
    }

    #[Test]
    public function upgradeRejectsTheCurrentTicketType(): void
    {
        $errors = $this->validateFormRequest(UpgradeTicketRequest::class, [
            'ticket_uuid' => $this->eventTicket->uuid,
            'amount' => 0,
        ], ['uuid' => $this->ticket->uuid]);

        $this->assertSame(['ticket_uuid' => ['Choose a different ticket type to upgrade to.']], $errors);
    }

    #[Test]
    public function upgradeRejectsInactiveBundleTicketTypes(): void
    {
        $bundle = $this->makeEventTicket($this->event, [
            'status' => GeneralConstants::GENERAL_STATUSES['INACTIVE'],
            'is_bundle' => true,
        ]);

        $errors = $this->validateFormRequest(UpgradeTicketRequest::class, [
            'ticket_uuid' => $bundle->uuid,
            'amount' => 100,
        ], ['uuid' => $this->ticket->uuid]);

        $this->assertSame(['ticket_uuid' => [
            'The selected ticket type is not active.',
            'Bundle ticket types cannot be used for upgrades.',
        ]], $errors);
    }

    #[Test]
    public function upgradeAcceptsAnotherActiveTicketTypeOfTheSameEvent(): void
    {
        $vip = $this->makeEventTicket($this->event);

        $request = $this->assertFormRequestPasses(UpgradeTicketRequest::class, [
            'ticket_uuid' => $vip->uuid,
            'amount' => 100,
        ], ['uuid' => $this->ticket->uuid]);

        $this->assertSame($this->ticket->uuid, $request->validated('uuid'));
    }

    #[Test]
    public function updateTicketSeatRejectsUnknownRelationsAndInvalidEnums(): void
    {
        $seat = $this->makeTicketSeat();

        $errors = $this->validateFormRequest(UpdateTicketSeatRequest::class, [
            'ticket_uuid' => self::UNKNOWN_UUID,
            'venue_uuid' => self::UNKNOWN_UUID,
            'venue_seat_uuid' => self::UNKNOWN_UUID,
            'row' => 0,
            'category' => 'diamond',
            'color' => 'purple',
        ], ['uuid' => $seat->uuid]);

        $this->assertSame('The selected ticket does not exist.', $errors['ticket_uuid'][0]);
        $this->assertSame('The selected venue seat does not exist.', $errors['venue_seat_uuid'][0]);
        $this->assertSame('The category must be one of: bronze, silver, gold, vip, svip.', $errors['category'][0]);
        $this->assertSame('The color must be one of: bronze, silver, gold, red, green.', $errors['color'][0]);
        $this->assertArrayHasKey('venue_uuid', $errors);
        $this->assertArrayHasKey('row', $errors);
    }

    #[Test]
    public function updateTicketSeatAcceptsValidChanges(): void
    {
        $seat = $this->makeTicketSeat();

        $request = $this->assertFormRequestPasses(UpdateTicketSeatRequest::class, [
            'ticket_uuid' => $this->ticket->uuid,
            'col' => 'B',
            'row' => 2,
            'seat_no' => 3,
            'category' => 'vip',
            'color' => 'red',
            'status' => 'reserved',
        ], ['uuid' => $seat->uuid]);

        $this->assertSame($seat->uuid, $request->validated('uuid'));
        $this->assertSame('vip', $request->validated('category'));
    }

    #[Test]
    public function updateTransactionRejectsUnknownRelationsAndDuplicateOrderNumbers(): void
    {
        $other = $this->makeTransaction($this->makeCustomer(), $this->event);

        $errors = $this->validateFormRequest(UpdateTransactionRequest::class, [
            'user_uuid' => self::UNKNOWN_UUID,
            'event_uuid' => self::UNKNOWN_UUID,
            'order_number' => $other->order_number,
            'total_amount' => -1,
        ], ['uuid' => $this->transaction->uuid]);

        $this->assertSame('The selected user does not exist.', $errors['user_uuid'][0]);
        $this->assertSame('The selected event does not exist.', $errors['event_uuid'][0]);
        $this->assertSame('This order number is already in use.', $errors['order_number'][0]);
        $this->assertArrayHasKey('total_amount', $errors);
    }

    #[Test]
    public function updateTransactionAcceptsItsOwnOrderNumber(): void
    {
        $request = $this->assertFormRequestPasses(UpdateTransactionRequest::class, [
            'order_number' => $this->transaction->order_number,
            'payment_status' => 'refunded',
            'total_amount' => 50,
        ], ['uuid' => $this->transaction->uuid]);

        $this->assertSame($this->transaction->uuid, $request->validated('uuid'));
        $this->assertSame('refunded', $request->validated('payment_status'));
    }

    #[Test]
    public function updateScheduleTimeSkipsCrossChecksForAnUnknownScheduleTime(): void
    {
        $errors = $this->validateFormRequest(UpdateScheduleTimeRequest::class, ['time_end' => '09:00:00'], ['uuid' => self::UNKNOWN_UUID]);

        $this->assertSame(['uuid'], array_keys($errors));
    }

    #[Test]
    public function updateScheduleTimeReportsAnUnknownSchedule(): void
    {
        $time = $this->makeScheduleTime($this->makeSchedule($this->event));

        $errors = $this->validateFormRequest(UpdateScheduleTimeRequest::class, [
            'schedule_uuid' => self::UNKNOWN_UUID,
            'time_end' => '09:00:00',
        ], ['uuid' => $time->uuid]);

        $this->assertSame(['schedule_uuid' => ['The selected schedule does not exist.']], $errors);
    }

    #[Test]
    public function updateScheduleTimeSkipsCrossChecksWhenAStoredTimeIsBlank(): void
    {
        $time = $this->makeScheduleTime($this->makeSchedule($this->event));
        DB::table('schedule_times')->where('uuid', $time->uuid)->update(['time_end' => '']);

        $request = $this->assertFormRequestPasses(UpdateScheduleTimeRequest::class, ['status' => 'draft'], ['uuid' => $time->uuid]);

        $this->assertSame('draft', $request->validated('status'));
    }

    #[Test]
    public function updateScheduleTimeRejectsAnEndBeforeTheStartOnSingleDaySchedules(): void
    {
        $time = $this->makeScheduleTime($this->makeSchedule($this->event));

        $errors = $this->validateFormRequest(UpdateScheduleTimeRequest::class, ['time_end' => '09:00:00'], ['uuid' => $time->uuid]);

        $this->assertSame(['time_end' => ['The end time must be after the start time.']], $errors);
    }

    #[Test]
    public function updateScheduleTimeAllowsOvernightSlotsOnMultiDaySchedules(): void
    {
        $schedule = $this->makeSchedule($this->event, ['date_from' => '2030-01-01', 'date_to' => '2030-01-02']);
        $time = $this->makeScheduleTime($schedule);
        $target = $this->makeSchedule($this->event, ['date_from' => '2030-02-01', 'date_to' => '2030-02-03']);

        $request = $this->assertFormRequestPasses(UpdateScheduleTimeRequest::class, [
            'schedule_uuid' => $target->uuid,
            'time_start' => '22:00:00',
            'time_end' => '02:00:00',
        ], ['uuid' => $time->uuid]);

        $this->assertSame($target->uuid, $request->validated('schedule_uuid'));
    }

    #[Test]
    public function updateScheduleTimeRejectsSlotsEndingAfterTheScheduleEndDate(): void
    {
        $schedule = $this->makeSchedule($this->event, ['date_from' => '2030-01-05', 'date_to' => '2030-01-01']);
        $time = $this->makeScheduleTime($schedule);

        $errors = $this->validateFormRequest(UpdateScheduleTimeRequest::class, ['time_end' => '13:00:00'], ['uuid' => $time->uuid]);

        $this->assertSame(['time_end' => ['The end time exceeds the schedule end date.']], $errors);
    }

    #[Test]
    public function updateScheduleTimeLeavesUnparsableTimesToTheFormatRule(): void
    {
        $time = $this->makeScheduleTime($this->makeSchedule($this->event));

        $errors = $this->validateFormRequest(UpdateScheduleTimeRequest::class, ['time_start' => 'quarter past'], ['uuid' => $time->uuid]);

        $this->assertSame(['time_start'], array_keys($errors));
        $this->assertCount(1, $errors['time_start']);
    }

    private function makeTicketSeat(): TicketSeat
    {
        $venue = $this->makeVenue();

        return TicketSeat::create([
            'ticket_uuid' => $this->ticket->uuid,
            'venue_uuid' => $venue->uuid,
            'venue_seat_uuid' => $this->makeVenueSeat($venue)->uuid,
            'col' => 'A',
            'row' => 1,
            'seat_no' => 1,
            'category' => 'gold',
            'color' => 'gold',
            'status' => 'active',
        ]);
    }
}
