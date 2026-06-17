<?php

namespace Tests\Feature;

use App\Constants\GeneralConstants;
use App\Models\AdminUser;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\Schedule;
use App\Models\ScheduleTime;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizerCustomerControllerTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organizationA;

    private Organization $organizationB;

    private AdminUser $organizerUser;

    private string $organizerToken;

    private Event $eventA;

    private Schedule $scheduleA;

    private ScheduleTime $scheduleTimeA;

    private User $customerForOrgA;

    private User $customerForOrgB;

    protected function setUp(): void
    {
        parent::setUp();

        $organizerRole = Role::create([
            'name' => 'Organizer',
            'code' => GeneralConstants::ROLES['ORGANIZER']['name'],
            'is_admin' => false,
        ]);

        $permission = Permission::create([
            'name' => 'Users',
            'code' => 'users',
            'available_access' => ['view', 'create', 'update', 'delete'],
            'role_scope' => 'shared',
        ]);

        foreach (['view', 'create', 'update', 'delete'] as $access) {
            RolePermission::create([
                'role_uuid' => $organizerRole->uuid,
                'permission_uuid' => $permission->uuid,
                'access' => 'users-' . $access,
            ]);
        }

        $this->organizationA = Organization::create([
            'name' => 'Merchant A',
            'representative_first_name' => 'Jane',
            'representative_last_name' => 'Doe',
            'email' => 'merchant_a_customers@test.com',
            'status' => GeneralConstants::ORGANIZER_STATUSES['APPROVED'],
        ]);

        $this->organizationB = Organization::create([
            'name' => 'Merchant B',
            'representative_first_name' => 'John',
            'representative_last_name' => 'Smith',
            'email' => 'merchant_b_customers@test.com',
            'status' => GeneralConstants::ORGANIZER_STATUSES['APPROVED'],
        ]);

        $this->organizerUser = AdminUser::create([
            'role_uuid' => $organizerRole->uuid,
            'organization_uuid' => $this->organizationA->uuid,
            'email' => 'organizer-customers@test.com',
            'password' => 'password123',
            'first_name' => 'Org',
            'last_name' => 'User',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);

        $this->organizerToken = auth('admin')->login($this->organizerUser) ?? '';

        $customerRole = Role::create([
            'name' => 'Customer',
            'code' => GeneralConstants::ROLES['CUSTOMER']['name'],
        ]);

        $this->customerForOrgA = User::create([
            'role_uuid' => $customerRole->uuid,
            'email' => 'customer_a@test.com',
            'password' => 'password123',
            'first_name' => 'Alice',
            'last_name' => 'Customer',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);

        $this->customerForOrgB = User::create([
            'role_uuid' => $customerRole->uuid,
            'email' => 'customer_b@test.com',
            'password' => 'password123',
            'first_name' => 'Bob',
            'last_name' => 'Customer',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);

        $this->eventA = Event::create([
            'event_name' => 'Org A Event',
            'event_description' => 'Test',
            'contact_email' => 'event_a@test.com',
            'event_type' => Event::EVENT_TYPES['SINGLE'],
            'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
            'event_config' => Event::EVENT_CONFIGS['OPEN_TICKET'],
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'organization_uuid' => $this->organizationA->uuid,
        ]);

        $this->scheduleA = Schedule::create([
            'event_uuid' => $this->eventA->uuid,
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-02',
            'status' => 'published',
        ]);

        $this->scheduleTimeA = ScheduleTime::create([
            'schedule_uuid' => $this->scheduleA->uuid,
            'time_start' => '10:00:00',
            'time_end' => '12:00:00',
            'status' => 'published',
        ]);

        Transaction::create([
            'user_uuid' => $this->customerForOrgA->uuid,
            'event_uuid' => $this->eventA->uuid,
            'schedule_uuid' => $this->scheduleA->uuid,
            'schedule_time_uuid' => $this->scheduleTimeA->uuid,
            'organization_uuid' => $this->organizationA->uuid,
            'order_number' => 'ORD-ORG-A-CUST-001',
            'total_amount' => 100.00,
            'sub_total' => 100.00,
            'tax_amount' => 0.00,
            'discount' => 0.00,
            'status' => 'completed',
            'payment_status' => 'paid',
            'order_status' => 'confirmed',
        ]);

        Transaction::create([
            'user_uuid' => $this->customerForOrgB->uuid,
            'event_uuid' => $this->eventA->uuid,
            'schedule_uuid' => $this->scheduleA->uuid,
            'schedule_time_uuid' => $this->scheduleTimeA->uuid,
            'organization_uuid' => $this->organizationB->uuid,
            'order_number' => 'ORD-ORG-B-CUST-001',
            'total_amount' => 200.00,
            'sub_total' => 200.00,
            'tax_amount' => 0.00,
            'discount' => 0.00,
            'status' => 'completed',
            'payment_status' => 'paid',
            'order_status' => 'confirmed',
        ]);
    }

    private function authHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->organizerToken,
        ];
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itListsOnlyCustomersLinkedToTheOrganizerOrganization(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/v1/organizer/customers?page=1&per_page=15');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.uuid', $this->customerForOrgA->uuid)
            ->assertJsonPath('data.0.email', 'customer_a@test.com');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itShowsCustomerDetailsWhenLinkedToOrganization(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/v1/organizer/customers/' . $this->customerForOrgA->uuid);

        $response->assertOk()
            ->assertJsonPath('data.uuid', $this->customerForOrgA->uuid);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itReturnsNotFoundForCustomersOutsideOrganization(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/v1/organizer/customers/' . $this->customerForOrgB->uuid);

        $response->assertNotFound();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itUpdatesCustomerLinkedToOrganization(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->putJson('/api/v1/organizer/customers/' . $this->customerForOrgA->uuid, [
                'first_name' => 'Updated',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.first_name', 'Updated');

        $this->assertDatabaseHas('users', [
            'uuid' => $this->customerForOrgA->uuid,
            'first_name' => 'Updated',
        ]);
    }
}
