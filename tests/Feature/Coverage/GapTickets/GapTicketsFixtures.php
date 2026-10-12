<?php

namespace Tests\Feature\Coverage\GapTickets;

use App\Constants\GeneralConstants;
use App\Models\AdminUser;
use App\Models\Event;
use App\Models\EventTicket;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\ScheduleTime;
use App\Models\Ticket;
use App\Models\TicketSeat;
use App\Models\Transaction;
use App\Models\TransactionOrder;
use App\Models\User;
use App\Models\Venue;
use App\Models\VenueSeat;
use Illuminate\Support\Str;

trait GapTicketsFixtures
{
    protected function customerRole(): Role
    {
        return Role::firstOrCreate(
            ['code' => GeneralConstants::ROLES['CUSTOMER']['name']],
            ['name' => 'Customer', 'is_admin' => false],
        );
    }

    protected function createAdmin(): AdminUser
    {
        $role = Role::firstOrCreate(
            ['code' => GeneralConstants::ROLES['SUPER_ADMIN']['name']],
            ['name' => 'Super Admin', 'is_admin' => true],
        );

        return AdminUser::create([
            'role_uuid' => $role->uuid,
            'email' => 'gap-admin-' . Str::random(6) . '@test.com',
            'password' => 'password123',
            'first_name' => 'Gap',
            'last_name' => 'Admin',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);
    }

    /**
     * Repository methods read the acting user from request()->user().
     */
    protected function actAsRequestUser(object $user): void
    {
        request()->setUserResolver(fn () => $user);
    }

    protected function createCustomer(array $attributes = []): User
    {
        return User::create(array_merge([
            'role_uuid' => $this->customerRole()->uuid,
            'email' => 'gap-customer-' . Str::random(6) . '@test.com',
            'password' => 'password123',
            'first_name' => 'Gap',
            'last_name' => 'Customer',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ], $attributes));
    }

    protected function customerHeaders(User $user): array
    {
        return ['Authorization' => 'Bearer ' . auth('api')->login($user)];
    }

    protected function createEvent(array $attributes = []): Event
    {
        return Event::create(array_merge([
            'event_name' => 'Gap Event ' . Str::random(4),
            'event_description' => 'Gap coverage event',
            'contact_email' => 'event@test.com',
            'event_type' => Event::EVENT_TYPES['SINGLE'],
            'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
            'event_config' => Event::EVENT_CONFIGS['OPEN_TICKET'],
            'status' => GeneralConstants::EVENT_STATUSES['PUBLISHED'],
            'ticket_sold' => 0,
        ], $attributes));
    }

    protected function createSchedule(Event $event): Schedule
    {
        return Schedule::create([
            'event_uuid' => $event->uuid,
            'date_from' => now()->addDays(5)->toDateString(),
            'date_to' => now()->addDays(6)->toDateString(),
            'status' => 'published',
        ]);
    }

    protected function createScheduleTime(Schedule $schedule): ScheduleTime
    {
        return ScheduleTime::create([
            'schedule_uuid' => $schedule->uuid,
            'time_start' => '10:00:00',
            'time_end' => '12:00:00',
            'status' => 'published',
        ]);
    }

    protected function createEventTicket(Event $event, array $attributes = []): EventTicket
    {
        return EventTicket::create(array_merge([
            'event_uuid' => $event->uuid,
            'code' => 'GA-' . Str::random(4),
            'name' => 'General',
            'description' => 'General admission',
            'price' => 100,
            'is_bundle' => false,
            'is_unlimited' => true,
            'sold_ticket' => 1,
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ], $attributes));
    }

    protected function createTransaction(User $user, Event $event, array $attributes = []): Transaction
    {
        return Transaction::create(array_merge([
            'user_uuid' => $user->uuid,
            'event_uuid' => $event->uuid,
            'order_number' => 'ORD-' . Str::upper(Str::random(8)),
            'total_amount' => 100,
            'sub_total' => 100,
            'tax_amount' => 0,
            'discount' => 0,
            'status' => Transaction::STATUS['ACTIVE'],
            'payment_status' => Transaction::PAYMENT_STATUS['PAID'],
            'order_status' => Transaction::ORDER_STATUS['CONFIRMED'],
        ], $attributes));
    }

    protected function createOrder(Transaction $transaction, EventTicket $eventTicket, array $attributes = []): TransactionOrder
    {
        return TransactionOrder::create(array_merge([
            'user_uuid' => $transaction->user_uuid,
            'transaction_uuid' => $transaction->uuid,
            'event_ticket_uuid' => $eventTicket->uuid,
            'quantity' => 1,
            'price' => 100,
            'total_amount' => 100,
            'discount' => 0,
        ], $attributes));
    }

    protected function createTicket(Transaction $transaction, EventTicket $eventTicket, array $attributes = []): Ticket
    {
        return Ticket::create(array_merge([
            'user_uuid' => $transaction->user_uuid,
            'transaction_uuid' => $transaction->uuid,
            'event_uuid' => $transaction->event_uuid,
            'event_ticket_uuid' => $eventTicket->uuid,
            'ticket_number' => 'T-' . Str::upper(Str::random(8)),
            'qr_code' => 'QR-' . Str::upper(Str::random(10)),
            'attendee_name' => 'Attendee',
            'attendee_email' => 'attendee@test.com',
            'status' => GeneralConstants::TICKET_STATUSES['ACTIVE'],
        ], $attributes));
    }

    protected function attachTicketSeat(Ticket $ticket): TicketSeat
    {
        $venue = $this->createVenue();
        $seat = $this->createVenueSeat($venue);

        return TicketSeat::create([
            'ticket_uuid' => $ticket->uuid,
            'venue_uuid' => $venue->uuid,
            'venue_seat_uuid' => $seat->uuid,
            'col' => $seat->col,
            'row' => $seat->row,
            'seat_no' => $seat->seat_no,
            'category' => 'gold',
            'color' => 'gold',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);
    }

    protected function createVenue(): Venue
    {
        return Venue::create([
            'name' => 'Gap Venue ' . Str::random(4),
            'code' => 'venue-' . Str::lower(Str::random(6)),
            'type' => Venue::TYPES[array_key_first(Venue::TYPES)],
        ]);
    }

    protected function createVenueSeat(Venue $venue, array $attributes = []): VenueSeat
    {
        return VenueSeat::create(array_merge([
            'venue_uuid' => $venue->uuid,
            'col' => 1,
            'row' => 1,
            'seat_no' => 1,
            'category' => 'gold',
            'color' => 'gold',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ], $attributes));
    }
}
