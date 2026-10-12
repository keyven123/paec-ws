<?php

namespace Tests\Feature\Coverage\RPixelTickets;

use App\Constants\GeneralConstants;
use App\Models\Category;
use App\Models\Event;
use App\Models\EventTicket;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\ScheduleTime;
use App\Models\Ticket;
use App\Models\Transaction;
use App\Models\TransactionOrder;
use App\Models\User;
use Illuminate\Support\Str;

trait RPixelTicketsFixtures
{
    protected function createCustomer(): User
    {
        $role = Role::firstOrCreate(
            ['code' => GeneralConstants::ROLES['CUSTOMER']['name']],
            ['name' => 'Customer', 'is_admin' => false],
        );

        return User::create([
            'role_uuid' => $role->uuid,
            'email' => 'pixel-' . Str::random(8) . '@test.com',
            'password' => 'password123',
            'first_name' => 'Pixel',
            'last_name' => 'Customer',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);
    }

    protected function createCategory(string $name = 'Concerts'): Category
    {
        return Category::create([
            'name' => $name,
            'code' => Str::slug($name) . '-' . Str::lower(Str::random(4)),
            'type' => 'event',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);
    }

    protected function createEvent(array $attributes = []): Event
    {
        return Event::create(array_merge([
            'event_name' => 'Pixel Event ' . Str::random(4),
            'event_description' => 'Coverage event',
            'contact_email' => 'event@test.com',
            'event_type' => Event::EVENT_TYPES['SINGLE'],
            'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
            'event_config' => Event::EVENT_CONFIGS['OPEN_TICKET'],
            'status' => GeneralConstants::EVENT_STATUSES['PUBLISHED'],
        ], $attributes));
    }

    protected function createSchedule(Event $event, array $attributes = []): Schedule
    {
        return Schedule::create(array_merge([
            'event_uuid' => $event->uuid,
            'date_from' => '2026-12-25',
            'date_to' => '2026-12-25',
            'status' => Schedule::PUBLISHED_STATUS,
        ], $attributes));
    }

    protected function createScheduleTime(Schedule $schedule, array $attributes = []): ScheduleTime
    {
        return ScheduleTime::create(array_merge([
            'schedule_uuid' => $schedule->uuid,
            'time_start' => '19:00:00',
            'time_end' => '21:30:00',
            'status' => ScheduleTime::PUBLISHED_STATUS,
        ], $attributes));
    }

    protected function createEventTicket(Event $event, array $attributes = []): EventTicket
    {
        return EventTicket::create(array_merge([
            'event_uuid' => $event->uuid,
            'code' => 'VIP-' . Str::upper(Str::random(4)),
            'name' => 'VIP',
            'description' => 'VIP ticket',
            'price' => 250,
            'is_bundle' => false,
        ], $attributes));
    }

    protected function createTransaction(User $user, Event $event, array $attributes = []): Transaction
    {
        return Transaction::create(array_merge([
            'user_uuid' => $user->uuid,
            'event_uuid' => $event->uuid,
            'order_number' => 'ORD-' . Str::upper(Str::random(8)),
            'sub_total' => 500,
            'tax_amount' => 0,
            'discount' => 0,
            'total_amount' => 500,
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
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
            'quantity' => 2,
            'price' => 250,
            'total_amount' => 500,
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
}
