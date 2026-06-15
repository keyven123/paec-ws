<?php

namespace Tests\Feature;

use App\Constants\GeneralConstants;
use App\Models\Event;
use App\Models\EventTicket;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\ScheduleTime;
use App\Models\Ticket;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ExpireUnusedTicketsCommandTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::create([
            'name' => 'Customer',
            'code' => 'customer-expire-test',
            'is_admin' => false,
        ]);

        $this->user = User::factory()->create(['role_uuid' => $role->uuid]);

        $this->event = Event::create([
            'event_name' => 'Expire Command Event',
            'event_description' => 'For expire-unused-tickets tests',
            'contact_email' => 'event@example.com',
            'event_type' => Event::EVENT_TYPES['SINGLE'],
            'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
            'event_config' => Event::EVENT_CONFIGS['SEAT_SELECTION'],
            'tags' => [],
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Active ticket linked to a schedule whose date_to is before today (Manila) becomes expired.
     */
    #[Test]
    public function it_expires_active_tickets_when_schedule_date_to_has_passed(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-20 12:00:00', 'Asia/Manila'));

        $ticket = $this->makeTicketWithScheduleEndDate(
            '2026-06-18',
            GeneralConstants::TICKET_STATUSES['ACTIVE']
        );

        $this->artisan('app:expire-unused-tickets')->assertSuccessful();

        $this->assertSame(
            GeneralConstants::TICKET_STATUSES['EXPIRED'],
            $ticket->fresh()->status
        );
    }

    /**
     * Tickets that are still valid by schedule date stay active.
     */
    #[Test]
    public function it_does_not_expire_active_tickets_when_schedule_has_not_passed(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-20 12:00:00', 'Asia/Manila'));

        $todayTicket = $this->makeTicketWithScheduleEndDate(
            '2026-06-20',
            GeneralConstants::TICKET_STATUSES['ACTIVE']
        );
        $futureTicket = $this->makeTicketWithScheduleEndDate(
            '2026-06-25',
            GeneralConstants::TICKET_STATUSES['ACTIVE']
        );

        $this->artisan('app:expire-unused-tickets')->assertSuccessful();

        $this->assertSame(
            GeneralConstants::TICKET_STATUSES['ACTIVE'],
            $todayTicket->fresh()->status
        );
        $this->assertSame(
            GeneralConstants::TICKET_STATUSES['ACTIVE'],
            $futureTicket->fresh()->status
        );
    }

    /**
     * Only active tickets are transitioned to expired; other statuses are left unchanged.
     */
    #[Test]
    public function it_only_updates_active_tickets_when_schedule_has_passed(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-20 12:00:00', 'Asia/Manila'));

        $pastDate = '2026-06-15';

        $active = $this->makeTicketWithScheduleEndDate($pastDate, GeneralConstants::TICKET_STATUSES['ACTIVE']);
        $used = $this->makeTicketWithScheduleEndDate($pastDate, GeneralConstants::TICKET_STATUSES['USED']);
        $pending = $this->makeTicketWithScheduleEndDate($pastDate, GeneralConstants::TICKET_STATUSES['PENDING']);

        $this->artisan('app:expire-unused-tickets')->assertSuccessful();

        $this->assertSame(GeneralConstants::TICKET_STATUSES['EXPIRED'], $active->fresh()->status);
        $this->assertSame(GeneralConstants::TICKET_STATUSES['USED'], $used->fresh()->status);
        $this->assertSame(GeneralConstants::TICKET_STATUSES['PENDING'], $pending->fresh()->status);
    }

    private function makeTicketWithScheduleEndDate(string $scheduleDateTo, string $ticketStatus): Ticket
    {
        $schedule = Schedule::create([
            'event_uuid' => $this->event->uuid,
            'date_from' => $scheduleDateTo,
            'date_to' => $scheduleDateTo,
            'status' => Schedule::PUBLISHED_STATUS,
        ]);

        $scheduleTime = ScheduleTime::create([
            'schedule_uuid' => $schedule->uuid,
            'time_start' => '10:00:00',
            'time_end' => '18:00:00',
            'status' => ScheduleTime::PUBLISHED_STATUS,
        ]);

        $eventTicket = EventTicket::create([
            'event_uuid' => $this->event->uuid,
            'schedule_uuid' => $schedule->uuid,
            'schedule_time_uuid' => $scheduleTime->uuid,
            'code' => 'T-' . Str::uuid()->toString(),
            'name' => 'General',
            'description' => 'Ticket type',
            'price' => 100,
            'is_bundle' => false,
        ]);

        $transaction = Transaction::create([
            'user_uuid' => $this->user->uuid,
            'event_uuid' => $this->event->uuid,
            'schedule_uuid' => $schedule->uuid,
            'schedule_time_uuid' => $scheduleTime->uuid,
            'order_number' => 'ORD-' . Str::uuid()->toString(),
            'sub_total' => 100,
            'tax_amount' => 0,
            'discount' => 0,
            'total_amount' => 100,
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'payment_status' => Transaction::PAYMENT_STATUS['PAID'],
            'order_status' => Transaction::ORDER_STATUS['CONFIRMED'],
        ]);

        return Ticket::create([
            'user_uuid' => $this->user->uuid,
            'transaction_uuid' => $transaction->uuid,
            'event_uuid' => $this->event->uuid,
            'event_ticket_uuid' => $eventTicket->uuid,
            'attendee_name' => 'Attendee',
            'attendee_email' => 'attendee-' . Str::uuid()->toString() . '@example.com',
            'status' => $ticketStatus,
        ]);
    }
}
