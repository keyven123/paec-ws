<?php

namespace Tests\Feature\Coverage\GapEvents;

use App\Constants\GeneralConstants;
use App\Models\AdminUser;
use App\Models\Event;
use App\Models\EventSection;
use App\Models\EventTicket;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\ScheduleTime;
use App\Models\Ticket;
use App\Models\Transaction;
use App\Models\TransactionOrder;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\GrantsAdminPermissions;
use Tests\TestCase;

abstract class GapEventsTestCase extends TestCase
{
    use GrantsAdminPermissions;
    use RefreshDatabase;

    /**
     * @param  array<string, list<string>>  $modules
     * @return array{0: AdminUser, 1: string}
     */
    protected function createAdmin(array $modules = [], bool $isAdmin = true, ?string $organizationUuid = null): array
    {
        $role = Role::create([
            'name' => 'Admin ' . Str::random(4),
            'code' => 'admin',
            'is_admin' => $isAdmin,
        ]);

        if ($modules !== []) {
            $this->grantRolePermissions($role, $modules);
        }

        $admin = AdminUser::create([
            'role_uuid' => $role->uuid,
            'organization_uuid' => $organizationUuid,
            'email' => 'gap-' . Str::lower(Str::random(8)) . '@test.com',
            'password' => 'password123',
            'first_name' => 'Gap',
            'last_name' => 'Admin',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);

        return [$admin, auth('admin')->login($admin)];
    }

    /**
     * @return array<string, string>
     */
    protected function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer ' . $token];
    }

    protected function section(string $name): EventSection
    {
        return EventSection::create([
            'name' => $name,
            'title' => Str::headline($name),
            'status' => 'active',
        ]);
    }

    protected function createOrganization(array $attributes = []): Organization
    {
        return Organization::factory()->create($attributes);
    }

    protected function createEvent(string $name, array $attributes = []): Event
    {
        return Event::create(array_merge([
            'event_name' => $name,
            'event_description' => 'Test',
            'contact_email' => Str::slug($name) . '@test.com',
            'event_type' => Event::EVENT_TYPES['SINGLE'],
            'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
            'event_config' => Event::EVENT_CONFIGS['OPEN_TICKET'],
            'status' => GeneralConstants::EVENT_STATUSES['PUBLISHED'],
            'organization_uuid' => $attributes['organization_uuid'] ?? $this->createOrganization()->uuid,
        ], $attributes));
    }

    protected function createSchedule(Event $event, bool $withTime = true): array
    {
        $schedule = Schedule::create([
            'event_uuid' => $event->uuid,
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-02',
            'status' => 'published',
        ]);

        $time = $withTime ? ScheduleTime::create([
            'schedule_uuid' => $schedule->uuid,
            'time_start' => '10:00:00',
            'time_end' => '12:00:00',
            'status' => 'published',
        ]) : null;

        return [$schedule, $time];
    }

    protected function createCustomer(string $email, array $attributes = []): User
    {
        $role = Role::firstOrCreate(
            ['code' => GeneralConstants::ROLES['CUSTOMER']['name']],
            ['name' => 'Customer'],
        );

        return User::create(array_merge([
            'role_uuid' => $role->uuid,
            'email' => $email,
            'password' => 'password123',
            'first_name' => 'Jane',
            'last_name' => 'Buyer',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ], $attributes));
    }

    protected function createEventTicket(Event $event, string $name, float $price, array $attributes = []): EventTicket
    {
        return EventTicket::create(array_merge([
            'event_uuid' => $event->uuid,
            'code' => Str::upper(Str::slug($name)) . '-' . Str::random(4),
            'name' => $name,
            'description' => $name,
            'price' => $price,
            'max_ticket' => 100,
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ], $attributes));
    }

    protected function createTransaction(?Event $event, User $user, float $amount, array $attributes = []): Transaction
    {
        $at = Carbon::parse($attributes['created_at'] ?? '2026-05-10 12:00:00');
        unset($attributes['created_at']);

        $transaction = new Transaction(array_merge([
            'user_uuid' => $user->uuid,
            'event_uuid' => $event?->uuid,
            'organization_uuid' => $event?->organization_uuid ?? $this->createOrganization()->uuid,
            'order_number' => 'ORD-' . Str::upper(Str::random(8)),
            'total_amount' => $amount,
            'sub_total' => $amount,
            'tax_amount' => 0,
            'discount' => 0,
            'promo_code_discount' => 0,
            'status' => Transaction::STATUS['ACTIVE'],
            'payment_status' => Transaction::PAYMENT_STATUS['PAID'],
            'order_status' => Transaction::ORDER_STATUS['CONFIRMED'],
            'paid_at' => $at,
        ], $attributes));

        $transaction->created_at = $at;
        $transaction->updated_at = $at;
        $transaction->save();

        return $transaction;
    }

    protected function createOrder(Transaction $transaction, ?EventTicket $eventTicket, int $quantity, float $price, array $attributes = []): TransactionOrder
    {
        return TransactionOrder::create(array_merge([
            'user_uuid' => $transaction->user_uuid,
            'transaction_uuid' => $transaction->uuid,
            'event_ticket_uuid' => $eventTicket?->uuid ?? (string) Str::uuid(),
            'quantity' => $quantity,
            'price' => $price,
            'discount' => 0,
            'total_amount' => $quantity * $price,
        ], $attributes));
    }

    protected function createTicket(Transaction $transaction, ?EventTicket $eventTicket, array $attributes = []): Ticket
    {
        $createdAt = $attributes['created_at'] ?? null;
        unset($attributes['created_at']);

        $ticket = new Ticket(array_merge([
            'user_uuid' => $transaction->user_uuid,
            'organization_uuid' => $transaction->organization_uuid,
            'transaction_uuid' => $transaction->uuid,
            'event_uuid' => $transaction->event_uuid,
            'event_ticket_uuid' => $eventTicket?->uuid,
            'ticket_number' => 'TKT-' . Str::upper(Str::random(8)),
            'attendee_name' => 'Attendee',
            'attendee_email' => 'attendee@test.com',
            'status' => 'active',
            'type' => 'paid',
            'price' => $eventTicket?->price ?? 0,
            'discount' => 0,
        ], $attributes));

        if ($createdAt !== null) {
            $ticket->created_at = Carbon::parse($createdAt);
            $ticket->updated_at = Carbon::parse($createdAt);
        }

        $ticket->save();

        return $ticket;
    }

    /**
     * @return list<list<string>>
     */
    protected function csvRows(string $csv): array
    {
        return array_map(
            fn (string $line) => explode('","', substr($line, 1, -1)),
            explode("\n", rtrim($csv, "\n")),
        );
    }
}
