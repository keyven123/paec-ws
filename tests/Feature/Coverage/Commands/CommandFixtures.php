<?php

namespace Tests\Feature\Coverage\Commands;

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

trait CommandFixtures
{
    protected function makeCustomer(array $attributes = []): User
    {
        $role = Role::firstOrCreate(
            ['code' => 'customer-command-coverage'],
            ['name' => 'Customer', 'is_admin' => false],
        );

        return User::factory()->create(array_merge(['role_uuid' => $role->uuid], $attributes));
    }

    /**
     * @return array{event: Event, schedule: Schedule, scheduleTime: ScheduleTime, eventTicket: EventTicket}
     */
    protected function makeEventOccurrence(string $date, string $timeStart = '10:00:00'): array
    {
        $event = Event::create([
            'event_name' => 'Command Coverage Event ' . Str::random(6),
            'event_description' => 'Console command coverage',
            'contact_email' => 'event@example.com',
            'event_type' => Event::EVENT_TYPES['SINGLE'],
            'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
            'event_config' => Event::EVENT_CONFIGS['OPEN_TICKET'],
            'tags' => [],
            'status' => GeneralConstants::EVENT_STATUSES['PUBLISHED'],
        ]);

        $schedule = Schedule::create([
            'event_uuid' => $event->uuid,
            'date_from' => $date,
            'date_to' => $date,
            'status' => Schedule::PUBLISHED_STATUS,
        ]);

        $scheduleTime = ScheduleTime::create([
            'schedule_uuid' => $schedule->uuid,
            'time_start' => $timeStart,
            'time_end' => '23:00:00',
            'status' => ScheduleTime::PUBLISHED_STATUS,
        ]);

        $eventTicket = EventTicket::create([
            'event_uuid' => $event->uuid,
            'schedule_uuid' => $schedule->uuid,
            'schedule_time_uuid' => $scheduleTime->uuid,
            'code' => 'T-' . Str::uuid()->toString(),
            'name' => 'General',
            'description' => 'Ticket type',
            'price' => 100,
            'is_bundle' => false,
        ]);

        return compact('event', 'schedule', 'scheduleTime', 'eventTicket');
    }

    protected function makeTransaction(User $user, array $occurrence, array $attributes = []): Transaction
    {
        return Transaction::create(array_merge([
            'user_uuid' => $user->uuid,
            'event_uuid' => $occurrence['event']->uuid,
            'schedule_uuid' => $occurrence['schedule']->uuid,
            'schedule_time_uuid' => $occurrence['scheduleTime']->uuid,
            'order_number' => 'ORD-' . Str::uuid()->toString(),
            'sub_total' => 100,
            'tax_amount' => 0,
            'discount' => 0,
            'total_amount' => 100,
            'status' => Transaction::STATUS['ACTIVE'],
            'payment_status' => Transaction::PAYMENT_STATUS['PAID'],
            'order_status' => Transaction::ORDER_STATUS['CONFIRMED'],
        ], $attributes));
    }

    protected function makeTicket(Transaction $transaction, array $occurrence, array $attributes = []): Ticket
    {
        return Ticket::create(array_merge([
            'user_uuid' => $transaction->user_uuid,
            'transaction_uuid' => $transaction->uuid,
            'event_uuid' => $occurrence['event']->uuid,
            'event_ticket_uuid' => $occurrence['eventTicket']->uuid,
            'attendee_name' => 'Attendee',
            'attendee_email' => 'attendee-' . Str::random(8) . '@example.com',
            'status' => GeneralConstants::TICKET_STATUSES['ACTIVE'],
        ], $attributes));
    }
}
