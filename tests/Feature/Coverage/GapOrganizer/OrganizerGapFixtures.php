<?php

namespace Tests\Feature\Coverage\GapOrganizer;

use App\Constants\GeneralConstants;
use App\Models\AdminUser;
use App\Models\Event;
use App\Models\EventTicket;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Shared fixtures for organizer controller coverage tests.
 */
trait OrganizerGapFixtures
{
    protected ?string $organizerToken = null;

    protected function makeOrganization(string $name = 'Gap Merchant'): Organization
    {
        return Organization::create([
            'name' => $name,
            'representative_first_name' => 'Rep',
            'representative_last_name' => 'Resentative',
            'email' => Str::slug($name) . '-' . Str::random(6) . '@example.com',
            'status' => GeneralConstants::ORGANIZER_STATUSES['APPROVED'],
            'commission_percentage' => 10,
        ]);
    }

    /**
     * @param  array<string, list<string>>  $modules  e.g. ['users' => ['view', 'create']]
     */
    protected function grantModules(Role $role, array $modules): void
    {
        foreach ($modules as $code => $accesses) {
            $permission = Permission::firstOrCreate(['code' => $code], [
                'name' => ucwords(str_replace('-', ' ', $code)),
                'available_access' => str_split('rwudxeia'),
                'role_scope' => 'shared',
            ]);

            foreach ($accesses as $access) {
                RolePermission::create([
                    'role_uuid' => $role->uuid,
                    'permission_uuid' => $permission->uuid,
                    'access' => $code . '-' . $access,
                ]);
            }
        }
    }

    /**
     * Creates an organizer admin (non platform-admin role) and logs it in on the admin guard.
     *
     * @param  array<string, list<string>>  $modules
     */
    protected function loginOrganizerAdmin(?Organization $organization, array $modules, array $roleOverrides = []): AdminUser
    {
        $role = Role::create(array_merge([
            'organization_uuid' => $organization?->uuid,
            'name' => 'Organizer ' . Str::random(4),
            'code' => GeneralConstants::ROLES['ORGANIZER']['name'],
            'is_admin' => false,
        ], $roleOverrides));

        $this->grantModules($role, $modules);

        $admin = AdminUser::create([
            'role_uuid' => $role->uuid,
            'organization_uuid' => $organization?->uuid,
            'email' => 'organizer-' . Str::random(8) . '@example.com',
            'password' => 'password123',
            'first_name' => 'Org',
            'last_name' => 'Admin',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);

        $this->organizerToken = auth('admin')->login($admin) ?? '';

        return $admin;
    }

    /**
     * @return array<string, string>
     */
    protected function organizerHeaders(): array
    {
        return ['Authorization' => 'Bearer ' . $this->organizerToken];
    }

    protected function customerRole(): Role
    {
        return Role::firstOrCreate(
            ['code' => GeneralConstants::ROLES['CUSTOMER']['name']],
            ['name' => 'Customer', 'is_admin' => false],
        );
    }

    protected function makeCustomer(string $email, string $firstName = 'Cust'): User
    {
        return User::create([
            'role_uuid' => $this->customerRole()->uuid,
            'email' => $email,
            'password' => 'password123',
            'first_name' => $firstName,
            'last_name' => 'Customer',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);
    }

    protected function makeOrgEvent(Organization $organization, string $name, array $attrs = []): Event
    {
        return Event::create(array_merge([
            'event_name' => $name,
            'event_description' => 'Test',
            'contact_email' => 'event@example.com',
            'event_type' => Event::EVENT_TYPES['SINGLE'],
            'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
            'event_config' => Event::EVENT_CONFIGS['OPEN_TICKET'],
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'organization_uuid' => $organization->uuid,
        ], $attrs));
    }

    protected function makeEventTicket(Event $event): EventTicket
    {
        return EventTicket::create([
            'event_uuid' => $event->uuid,
            'code' => 'GA-' . Str::random(6),
            'name' => 'General Admission',
            'price' => 100,
            'max_ticket' => 100,
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);
    }

    protected function makeOrgTransaction(Organization $organization, ?Event $event, ?User $user, float $amount, array $attrs = []): Transaction
    {
        return Transaction::create(array_merge([
            'user_uuid' => $user?->uuid ?? (string) Str::uuid(),
            'event_uuid' => $event?->uuid,
            'organization_uuid' => $organization->uuid,
            'order_number' => 'ORD-GAP-' . Str::random(10),
            'sub_total' => $amount,
            'tax_amount' => 0,
            'discount' => 0,
            'total_amount' => $amount,
            'status' => Transaction::STATUS['ACTIVE'],
            'payment_status' => Transaction::PAYMENT_STATUS['PAID'],
            'order_status' => Transaction::ORDER_STATUS['CONFIRMED'],
            'paid_at' => now(),
        ], $attrs));
    }
}
