<?php

namespace Tests\Feature\Coverage\GapAdmin\Concerns;

use App\Constants\GeneralConstants;
use App\Models\Event;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\ScheduleTime;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Str;

trait CreatesGapPaidTransactions
{
    protected function createGapCustomer(string $firstName = 'Test', string $lastName = 'Customer'): User
    {
        $role = Role::query()->firstOrCreate(
            ['code' => GeneralConstants::ROLES['CUSTOMER']['name']],
            ['name' => 'Customer'],
        );

        return User::create([
            'role_uuid' => $role->uuid,
            'email' => 'gap-customer-'.Str::lower(Str::random(8)).'@test.com',
            'password' => 'password123',
            'first_name' => $firstName,
            'last_name' => $lastName,
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function createGapPaidTransaction(Event $event, User $customer, array $overrides = []): Transaction
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
            'order_number' => 'ORD-'.uniqid('', true),
            'total_amount' => 1000,
            'sub_total' => 1000,
            'tax_amount' => 0,
            'discount' => 0,
            'status' => Transaction::STATUS['ACTIVE'],
            'payment_status' => Transaction::PAYMENT_STATUS['PAID'],
            'order_status' => Transaction::ORDER_STATUS['CONFIRMED'],
            'paid_at' => now(),
        ], $overrides));
    }
}
