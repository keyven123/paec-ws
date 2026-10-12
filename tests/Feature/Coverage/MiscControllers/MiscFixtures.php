<?php

namespace Tests\Feature\Coverage\MiscControllers;

use App\Constants\GeneralConstants;
use App\Models\AdminUser;
use App\Models\Event;
use App\Models\EventTicket;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\Schedule;
use App\Models\ScheduleTime;
use App\Models\Ticket;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Venue;
use App\Models\VenueSeat;
use Illuminate\Support\Str;

trait MiscFixtures
{
    protected ?AdminUser $superAdmin = null;

    protected ?string $superAdminToken = null;

    protected function superAdminRole(): Role
    {
        return Role::firstOrCreate(
            ['code' => GeneralConstants::ROLES['SUPER_ADMIN']['name']],
            ['name' => 'Super Admin', 'is_admin' => true],
        );
    }

    protected function customerRole(): Role
    {
        return Role::firstOrCreate(
            ['code' => GeneralConstants::ROLES['CUSTOMER']['name']],
            ['name' => 'Customer', 'is_admin' => false],
        );
    }

    protected function createSuperAdmin(array $attributes = []): AdminUser
    {
        $this->superAdmin = AdminUser::create(array_merge([
            'role_uuid' => $this->superAdminRole()->uuid,
            'email' => 'super-' . Str::random(6) . '@test.com',
            'password' => 'password123',
            'first_name' => 'Super',
            'last_name' => 'Admin',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ], $attributes));

        $this->superAdminToken = auth('admin')->login($this->superAdmin);

        return $this->superAdmin;
    }

    /**
     * Create an admin with a non-superadmin role holding exactly the given access strings.
     *
     * @param  list<string>  $accesses  e.g. ['users-view']
     */
    protected function createAdminWithAccess(array $accesses, array $attributes = []): array
    {
        $role = Role::create([
            'name' => 'Limited ' . Str::random(4),
            'code' => 'limited_' . Str::lower(Str::random(6)),
            'is_admin' => true,
        ]);

        foreach ($accesses as $access) {
            $code = Str::beforeLast($access, '-');
            $permission = Permission::firstOrCreate(
                ['code' => $code],
                ['name' => Str::headline($code), 'available_access' => ['view', 'create', 'update', 'delete']],
            );
            RolePermission::create([
                'role_uuid' => $role->uuid,
                'permission_uuid' => $permission->uuid,
                'access' => $access,
            ]);
        }

        $admin = AdminUser::create(array_merge([
            'role_uuid' => $role->uuid,
            'email' => 'limited-' . Str::random(6) . '@test.com',
            'password' => 'password123',
            'first_name' => 'Limited',
            'last_name' => 'Admin',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ], $attributes));

        return [$admin, auth('admin')->login($admin)];
    }

    protected function adminHeaders(?string $token = null): array
    {
        if ($token === null && $this->superAdminToken === null) {
            $this->createSuperAdmin();
        }

        return ['Authorization' => 'Bearer ' . ($token ?? $this->superAdminToken)];
    }

    protected function createCustomer(array $attributes = []): User
    {
        return User::create(array_merge([
            'role_uuid' => $this->customerRole()->uuid,
            'email' => 'customer-' . Str::random(6) . '@test.com',
            'password' => 'password123',
            'first_name' => 'Cust',
            'last_name' => 'Omer',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ], $attributes));
    }

    protected function createEvent(array $attributes = []): Event
    {
        return Event::create(array_merge([
            'event_name' => 'Coverage Event ' . Str::random(4),
            'event_description' => 'Coverage event',
            'contact_email' => 'event@test.com',
            'event_type' => Event::EVENT_TYPES['SINGLE'],
            'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
            'event_config' => Event::EVENT_CONFIGS['SEAT_SELECTION'],
            'status' => GeneralConstants::EVENT_STATUSES['PUBLISHED'],
        ], $attributes));
    }

    protected function createSchedule(Event $event, array $attributes = []): Schedule
    {
        return Schedule::create(array_merge([
            'event_uuid' => $event->uuid,
            'date_from' => now()->addDays(5)->toDateString(),
            'date_to' => now()->addDays(6)->toDateString(),
            'status' => 'published',
        ], $attributes));
    }

    protected function createScheduleTime(Schedule $schedule, array $attributes = []): ScheduleTime
    {
        return ScheduleTime::create(array_merge([
            'schedule_uuid' => $schedule->uuid,
            'time_start' => '10:00:00',
            'time_end' => '12:00:00',
            'status' => 'published',
        ], $attributes));
    }

    protected function createEventTicket(Event $event, array $attributes = []): EventTicket
    {
        return EventTicket::create(array_merge([
            'event_uuid' => $event->uuid,
            'code' => 'GA-' . Str::random(4),
            'name' => 'General',
            'description' => 'General admission',
            'price' => 100,
            'max_ticket' => 100,
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ], $attributes));
    }

    protected function createTransaction(User $user, Event $event, array $attributes = []): Transaction
    {
        return Transaction::create(array_merge([
            'user_uuid' => $user->uuid,
            'event_uuid' => $event->uuid,
            'payment_method' => 'card',
            'order_number' => 'ORD-' . Str::upper(Str::random(8)),
            'total_amount' => 1000,
            'sub_total' => 1000,
            'tax_amount' => 0,
            'discount' => 0,
            'status' => 'completed',
            'payment_status' => Transaction::PAYMENT_STATUS['PAID'],
            'order_status' => 'confirmed',
        ], $attributes));
    }

    protected function createTicket(Transaction $transaction, array $attributes = []): Ticket
    {
        if (!array_key_exists('event_ticket_uuid', $attributes)) {
            $attributes['event_ticket_uuid'] = $this->createEventTicket(Event::findOrFail($transaction->event_uuid))->uuid;
        }

        return Ticket::create(array_merge([
            'user_uuid' => $transaction->user_uuid,
            'transaction_uuid' => $transaction->uuid,
            'event_uuid' => $transaction->event_uuid,
            'ticket_number' => 'T-' . Str::upper(Str::random(8)),
            'attendee_name' => 'Attendee',
            'attendee_email' => 'attendee@test.com',
            'status' => GeneralConstants::TICKET_STATUSES['ACTIVE'],
            'price' => 1000,
            'discount' => 0,
        ], $attributes));
    }

    protected function createVenueSeat(Venue $venue, array $attributes = []): VenueSeat
    {
        return VenueSeat::create(array_merge([
            'venue_uuid' => $venue->uuid,
            'col' => 1,
            'row' => 'A',
            'seat_no' => 1,
            'category' => 'gold',
            'color' => 'gold',
            'status' => 'active',
        ], $attributes));
    }

    protected function createVenue(array $attributes = []): Venue
    {
        return Venue::create(array_merge([
            'name' => 'Coverage Venue ' . Str::random(4),
            'code' => 'venue-' . Str::lower(Str::random(6)),
            'type' => Venue::TYPES[array_key_first(Venue::TYPES)],
        ], $attributes));
    }
}
