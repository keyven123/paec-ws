<?php

namespace Tests\Feature;

use App\Constants\GeneralConstants;
use App\Models\Event;
use App\Models\EventTicket;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\ScheduleTime;
use App\Models\Ticket;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PublicEventTicketsLimitPerUserTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Event $event;
    private Schedule $schedule;
    private ScheduleTime $scheduleTime;
    private EventTicket $ticketA;
    private EventTicket $ticketB;

    protected function setUp(): void
    {
        parent::setUp();

        $customerRole = Role::create([
            'name' => 'Customer',
            'code' => GeneralConstants::ROLES['CUSTOMER']['name'],
        ]);

        $this->user = User::factory()->create([
            'role_uuid' => $customerRole->uuid,
            'email' => 'public-tickets-limit@test.com',
        ]);

        $organization = Organization::create([
            'name' => 'Public Tickets Limit Org',
            'representative_first_name' => 'Org',
            'representative_last_name' => 'Rep',
            'email' => 'org-public-tickets-limit@test.com',
            'status' => GeneralConstants::ORGANIZER_STATUSES['APPROVED'],
        ]);

        $this->event = Event::create([
            'organization_uuid' => $organization->uuid,
            'event_name' => 'Public Tickets Limit Event',
            'event_description' => 'Testing public tickets response fields',
            'contact_email' => 'event-public-tickets-limit@test.com',
            'event_type' => Event::EVENT_TYPES['SINGLE'],
            'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
            'event_config' => Event::EVENT_CONFIGS['SEAT_SELECTION'],
            'status' => GeneralConstants::EVENT_STATUSES['PUBLISHED'],
            'tags' => [],
        ]);

        $this->schedule = Schedule::create([
            'event_uuid' => $this->event->uuid,
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-02',
            'status' => GeneralConstants::SCHEDULE_STATUSES['PUBLISHED'],
        ]);

        $this->scheduleTime = ScheduleTime::create([
            'schedule_uuid' => $this->schedule->uuid,
            'time_start' => '10:00:00',
            'time_end' => '12:00:00',
            'status' => GeneralConstants::SCHEDULE_STATUSES['PUBLISHED'],
        ]);

        $this->ticketA = EventTicket::create([
            'event_uuid' => $this->event->uuid,
            'schedule_uuid' => $this->schedule->uuid,
            'schedule_time_uuid' => $this->scheduleTime->uuid,
            'code' => 'LIMIT-A',
            'name' => 'Ticket A',
            'description' => 'A',
            'price' => 100.00,
            'is_bundle' => false,
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'is_unlimited' => false,
            'max_ticket' => 100,
            'sold_ticket' => 0,
            'ticket_limit_per_user' => 5,
        ]);

        $this->ticketB = EventTicket::create([
            'event_uuid' => $this->event->uuid,
            'schedule_uuid' => $this->schedule->uuid,
            'schedule_time_uuid' => $this->scheduleTime->uuid,
            'code' => 'LIMIT-B',
            'name' => 'Ticket B',
            'description' => 'B',
            'price' => 100.00,
            'is_bundle' => false,
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'is_unlimited' => false,
            'max_ticket' => 100,
            'sold_ticket' => 0,
            'ticket_limit_per_user' => 5,
        ]);

        $transactionPaid = Transaction::create([
            'user_uuid' => $this->user->uuid,
            'event_uuid' => $this->event->uuid,
            'schedule_uuid' => $this->schedule->uuid,
            'schedule_time_uuid' => $this->scheduleTime->uuid,
            'payment_method' => 'card',
            'order_number' => 'ORD-LIMIT-' . uniqid(),
            'total_amount' => 100.00,
            'sub_total' => 100.00,
            'tax_amount' => 0.00,
            'discount' => 0.00,
            'status' => Transaction::STATUS['ACTIVE'],
            'payment_status' => Transaction::PAYMENT_STATUS['PAID'],
            'order_status' => Transaction::ORDER_STATUS['CONFIRMED'],
        ]);

        // User bought 1 of ticketA
        Ticket::create([
            'user_uuid' => $this->user->uuid,
            'transaction_uuid' => $transactionPaid->uuid,
            'event_uuid' => $this->event->uuid,
            'event_ticket_uuid' => $this->ticketA->uuid,
            'attendee_name' => 'Buyer A',
            'attendee_email' => 'buyer-a@test.com',
            'qr_code' => 'QR-LIMIT-A-' . uniqid(),
            'status' => GeneralConstants::TICKET_STATUSES['ACTIVE'],
            'is_downloaded' => false,
        ]);

        // User bought 3 of ticketB (ensure the count is per event_ticket_uuid)
        for ($i = 0; $i < 3; $i++) {
            Ticket::create([
                'user_uuid' => $this->user->uuid,
                'transaction_uuid' => $transactionPaid->uuid,
                'event_uuid' => $this->event->uuid,
                'event_ticket_uuid' => $this->ticketB->uuid,
                'attendee_name' => 'Buyer B ' . $i,
                'attendee_email' => "buyer-b-{$i}@test.com",
                'qr_code' => 'QR-LIMIT-B-' . uniqid() . '-' . $i,
                'status' => GeneralConstants::TICKET_STATUSES['ACTIVE'],
                'is_downloaded' => false,
            ]);
        }
    }

    #[Test]
    public function publicEventTicketsReturnsRawLimitForUnauthenticatedUsers(): void
    {
        $response = $this->getJson('/api/v1/public/events/' . $this->event->uuid . '/tickets?schedule_uuid=' . $this->schedule->uuid . '&schedule_time_uuid=' . $this->scheduleTime->uuid);

        $response->assertOk();

        $payload = $response->json('data');
        $this->assertIsArray($payload);
        $this->assertCount(2, $payload);

        foreach ($payload as $row) {
            $this->assertSame(5, $row['ticket_limit_per_user']);
            $this->assertSame(0, $row['bought_ticket_count']);
            $this->assertSame(5, $row['remaining_ticket_limit_per_user']);
        }
    }

    #[Test]
    public function publicEventTicketsReturnsBoughtCountAndRemainingLimitPerEventTicketForAuthenticatedUsers(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/v1/public/events/' . $this->event->uuid . '/tickets?schedule_uuid=' . $this->schedule->uuid . '&schedule_time_uuid=' . $this->scheduleTime->uuid);

        $response->assertOk();

        $rows = collect($response->json('data'))->keyBy('uuid');

        $this->assertSame(5, $rows[$this->ticketA->uuid]['ticket_limit_per_user']);
        $this->assertSame(1, $rows[$this->ticketA->uuid]['bought_ticket_count']);
        $this->assertSame(4, $rows[$this->ticketA->uuid]['remaining_ticket_limit_per_user']);

        $this->assertSame(5, $rows[$this->ticketB->uuid]['ticket_limit_per_user']);
        $this->assertSame(3, $rows[$this->ticketB->uuid]['bought_ticket_count']);
        $this->assertSame(2, $rows[$this->ticketB->uuid]['remaining_ticket_limit_per_user']);
    }
}
