<?php

namespace Tests\Feature\Coverage\RServicesJobs\Concerns;

use App\Constants\GeneralConstants;
use App\Models\Event;
use App\Models\EventTicket;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\ScheduleTime;
use App\Models\Ticket;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Str;

trait CreatesRServicesJobsFixtures
{
    protected function createRsjUser(string $firstName = 'Rsj', array $attributes = []): User
    {
        $role = Role::query()->firstOrCreate(
            ['code' => GeneralConstants::ROLES['CUSTOMER']['name']],
            ['name' => 'Customer'],
        );

        return User::create(array_merge([
            'role_uuid' => $role->uuid,
            'email' => 'rsj-'.Str::lower(Str::random(8)).'@test.com',
            'password' => 'password123',
            'first_name' => $firstName,
            'last_name' => 'User',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ], $attributes));
    }

    protected function createRsjEvent(array $attributes = []): Event
    {
        return Event::create(array_merge([
            'event_name' => 'Rsj Event '.Str::random(4),
            'event_description' => 'Coverage event',
            'contact_email' => 'event@test.com',
            'event_type' => Event::EVENT_TYPES['DAILY'],
            'schedule_type' => Event::SCHEDULE_TYPES['DAILY'],
            'event_config' => Event::EVENT_CONFIGS['OPEN_TICKET'],
            'status' => GeneralConstants::EVENT_STATUSES['PUBLISHED'],
        ], $attributes));
    }

    protected function createRsjEventTicket(Event $event, array $attributes = []): EventTicket
    {
        return EventTicket::create(array_merge([
            'event_uuid' => $event->uuid,
            'code' => 'GA-'.Str::upper(Str::random(4)),
            'name' => 'General',
            'description' => 'General admission',
            'price' => 500,
            'max_ticket' => 100,
            'is_unlimited' => true,
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ], $attributes));
    }

    protected function createRsjTransaction(Event $event, User $customer, array $attributes = []): Transaction
    {
        $schedule = Schedule::create([
            'event_uuid' => $event->uuid,
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-02',
            'status' => 'published',
        ]);

        $scheduleTime = ScheduleTime::create([
            'schedule_uuid' => $schedule->uuid,
            'time_start' => '10:00:00',
            'time_end' => '12:00:00',
            'status' => 'published',
        ]);

        return Transaction::create(array_merge([
            'user_uuid' => $customer->uuid,
            'event_uuid' => $event->uuid,
            'schedule_uuid' => $schedule->uuid,
            'schedule_time_uuid' => $scheduleTime->uuid,
            'organization_uuid' => $event->organization_uuid,
            'order_number' => 'ORD-'.Str::upper(Str::random(10)),
            'total_amount' => 1000,
            'sub_total' => 1000,
            'tax_amount' => 0,
            'discount' => 0,
            'status' => Transaction::STATUS['ACTIVE'],
            'payment_status' => Transaction::PAYMENT_STATUS['PAID'],
            'order_status' => Transaction::ORDER_STATUS['CONFIRMED'],
            'paid_at' => now(),
        ], $attributes));
    }

    protected function createRsjTicket(Transaction $transaction, EventTicket $eventTicket, array $attributes = []): Ticket
    {
        return Ticket::create(array_merge([
            'user_uuid' => $transaction->user_uuid,
            'transaction_uuid' => $transaction->uuid,
            'event_uuid' => $transaction->event_uuid,
            'event_ticket_uuid' => $eventTicket->uuid,
            'ticket_number' => 'T-'.Str::upper(Str::random(8)),
            'attendee_name' => 'Attendee',
            'attendee_email' => 'attendee@test.com',
            'status' => GeneralConstants::TICKET_STATUSES['ACTIVE'],
            'price' => 500,
            'discount' => 0,
        ], $attributes));
    }
}
