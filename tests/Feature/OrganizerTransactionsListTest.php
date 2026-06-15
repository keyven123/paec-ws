<?php

namespace Tests\Feature;

use App\Constants\GeneralConstants;
use App\Models\AdminUser;
use App\Models\AffiliateConversion;
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

class OrganizerTransactionsListTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organizationA;

    private Organization $organizationB;

    private AdminUser $organizerUser;

    private string $organizerToken;

    private Event $eventA;

    private Schedule $scheduleA;

    private ScheduleTime $scheduleTimeA;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $organizerRole = Role::create([
            'name' => 'Organizer',
            'code' => GeneralConstants::ROLES['ORGANIZER']['name'],
            'is_admin' => false,
        ]);

        $permission = Permission::create([
            'name' => 'Transactions',
            'code' => 'transactions',
            'available_access' => ['view'],
        ]);

        RolePermission::create([
            'role_uuid' => $organizerRole->uuid,
            'permission_uuid' => $permission->uuid,
            'access' => 'transactions-view',
        ]);

        $this->organizationA = Organization::create([
            'name' => 'Merchant A',
            'representative_first_name' => 'Jane',
            'representative_last_name' => 'Doe',
            'email' => 'merchant_a@test.com',
            'status' => GeneralConstants::ORGANIZER_STATUSES['APPROVED'],
        ]);

        $this->organizationB = Organization::create([
            'name' => 'Merchant B',
            'representative_first_name' => 'John',
            'representative_last_name' => 'Smith',
            'email' => 'merchant_b@test.com',
            'status' => GeneralConstants::ORGANIZER_STATUSES['APPROVED'],
        ]);

        $this->organizerUser = AdminUser::create([
            'role_uuid' => $organizerRole->uuid,
            'organization_uuid' => $this->organizationA->uuid,
            'email' => 'organizer@test.com',
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

        $this->customer = User::create([
            'role_uuid' => $customerRole->uuid,
            'email' => 'customer@test.com',
            'password' => 'password123',
            'first_name' => 'Test',
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
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itListsOnlyTransactionsForTheAuthenticatedOrganizerOrganization(): void
    {
        $ownTransaction = Transaction::create([
            'user_uuid' => $this->customer->uuid,
            'event_uuid' => $this->eventA->uuid,
            'schedule_uuid' => $this->scheduleA->uuid,
            'schedule_time_uuid' => $this->scheduleTimeA->uuid,
            'organization_uuid' => $this->organizationA->uuid,
            'order_number' => 'ORD-ORG-A-001',
            'total_amount' => 100.00,
            'sub_total' => 100.00,
            'tax_amount' => 0.00,
            'discount' => 0.00,
            'status' => 'completed',
            'payment_status' => 'paid',
            'order_status' => 'confirmed',
        ]);

        Transaction::create([
            'user_uuid' => $this->customer->uuid,
            'event_uuid' => $this->eventA->uuid,
            'schedule_uuid' => $this->scheduleA->uuid,
            'schedule_time_uuid' => $this->scheduleTimeA->uuid,
            'organization_uuid' => $this->organizationB->uuid,
            'order_number' => 'ORD-ORG-B-001',
            'total_amount' => 200.00,
            'sub_total' => 200.00,
            'tax_amount' => 0.00,
            'discount' => 0.00,
            'status' => 'completed',
            'payment_status' => 'paid',
            'order_status' => 'confirmed',
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->organizerToken,
        ])->getJson('/api/v1/transactions');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.uuid', $ownTransaction->uuid)
            ->assertJsonPath('data.0.order_number', 'ORD-ORG-A-001');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itRejectsOrganizationUuidFilterForOrganizerUsers(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->organizerToken,
        ])->getJson('/api/v1/transactions?organization_uuid=' . $this->organizationB->uuid);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['organization_uuid']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itFiltersTransactionsBySearchKeywordForOrganizer(): void
    {
        Transaction::create([
            'user_uuid' => $this->customer->uuid,
            'event_uuid' => $this->eventA->uuid,
            'schedule_uuid' => $this->scheduleA->uuid,
            'schedule_time_uuid' => $this->scheduleTimeA->uuid,
            'organization_uuid' => $this->organizationA->uuid,
            'order_number' => 'ORD-MATCH-ME',
            'total_amount' => 50.00,
            'sub_total' => 50.00,
            'tax_amount' => 0.00,
            'discount' => 0.00,
            'status' => 'completed',
            'payment_status' => 'paid',
            'order_status' => 'confirmed',
        ]);

        Transaction::create([
            'user_uuid' => $this->customer->uuid,
            'event_uuid' => $this->eventA->uuid,
            'schedule_uuid' => $this->scheduleA->uuid,
            'schedule_time_uuid' => $this->scheduleTimeA->uuid,
            'organization_uuid' => $this->organizationA->uuid,
            'order_number' => 'ORD-OTHER-999',
            'total_amount' => 75.00,
            'sub_total' => 75.00,
            'tax_amount' => 0.00,
            'discount' => 0.00,
            'status' => 'completed',
            'payment_status' => 'paid',
            'order_status' => 'confirmed',
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->organizerToken,
        ])->getJson('/api/v1/transactions?q=MATCH-ME');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.order_number', 'ORD-MATCH-ME');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itShowsOwnOrganizationTransactionDetail(): void
    {
        $transaction = Transaction::create([
            'user_uuid' => $this->customer->uuid,
            'event_uuid' => $this->eventA->uuid,
            'schedule_uuid' => $this->scheduleA->uuid,
            'schedule_time_uuid' => $this->scheduleTimeA->uuid,
            'organization_uuid' => $this->organizationA->uuid,
            'order_number' => 'ORD-DETAIL-001',
            'total_amount' => 150.00,
            'sub_total' => 150.00,
            'tax_amount' => 0.00,
            'discount' => 0.00,
            'status' => 'completed',
            'payment_status' => 'paid',
            'order_status' => 'confirmed',
        ]);

        $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->organizerToken,
        ])->getJson('/api/v1/transactions/' . $transaction->uuid)
            ->assertStatus(200)
            ->assertJsonPath('data.uuid', $transaction->uuid)
            ->assertJsonPath('data.order_number', 'ORD-DETAIL-001')
            ->assertJsonMissingPath('data.affiliate_commission_amount')
            ->assertJsonMissingPath('data.affiliate_commission_percent');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itReturnsNotFoundWhenViewingAnotherOrganizationsTransaction(): void
    {
        $otherTransaction = Transaction::create([
            'user_uuid' => $this->customer->uuid,
            'event_uuid' => $this->eventA->uuid,
            'schedule_uuid' => $this->scheduleA->uuid,
            'schedule_time_uuid' => $this->scheduleTimeA->uuid,
            'organization_uuid' => $this->organizationB->uuid,
            'order_number' => 'ORD-OTHER-DETAIL',
            'total_amount' => 99.00,
            'sub_total' => 99.00,
            'tax_amount' => 0.00,
            'discount' => 0.00,
            'status' => 'completed',
            'payment_status' => 'paid',
            'order_status' => 'confirmed',
        ]);

        $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->organizerToken,
        ])->getJson('/api/v1/transactions/' . $otherTransaction->uuid)
            ->assertStatus(404);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function platformAdminSeesAffiliateCommissionOnTransactionDetail(): void
    {
        $adminRole = Role::create([
            'name' => 'Platform Admin',
            'code' => GeneralConstants::ROLES['ADMIN']['name'],
            'is_admin' => true,
        ]);

        $permission = Permission::where('code', 'transactions')->first();
        RolePermission::firstOrCreate([
            'role_uuid' => $adminRole->uuid,
            'permission_uuid' => $permission->uuid,
            'access' => 'transactions-view',
        ]);

        $adminUser = AdminUser::create([
            'role_uuid' => $adminRole->uuid,
            'email' => 'platform_admin@test.com',
            'password' => 'password123',
            'first_name' => 'Platform',
            'last_name' => 'Admin',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);

        $adminToken = auth('admin')->login($adminUser) ?? '';

        $affiliatePartner = User::create([
            'role_uuid' => $this->customer->role_uuid,
            'email' => 'affiliate@test.com',
            'password' => 'password123',
            'first_name' => 'Aff',
            'last_name' => 'Partner',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);

        $this->eventA->update([
            'affiliate_enabled' => true,
            'affiliate_commission_percent' => 8,
        ]);

        $transaction = Transaction::create([
            'user_uuid' => $this->customer->uuid,
            'event_uuid' => $this->eventA->uuid,
            'schedule_uuid' => $this->scheduleA->uuid,
            'schedule_time_uuid' => $this->scheduleTimeA->uuid,
            'organization_uuid' => $this->organizationA->uuid,
            'affiliate_partner_uuid' => $affiliatePartner->uuid,
            'order_number' => 'ORD-AFF-001',
            'total_amount' => 200.00,
            'sub_total' => 200.00,
            'tax_amount' => 0.00,
            'discount' => 0.00,
            'status' => 'completed',
            'payment_status' => 'paid',
            'order_status' => 'confirmed',
        ]);

        AffiliateConversion::create([
            'partner_user_uuid' => $affiliatePartner->uuid,
            'transaction_uuid' => $transaction->uuid,
            'entry_type' => AffiliateConversion::ENTRY_TYPE_CREDIT,
            'event_uuid' => $this->eventA->uuid,
            'order_total' => 200.00,
            'commission_percent' => 8,
            'commission_amount' => 16.00,
        ]);

        $this->withHeaders([
            'Authorization' => 'Bearer ' . $adminToken,
        ])->getJson('/api/v1/transactions/' . $transaction->uuid)
            ->assertStatus(200)
            ->assertJsonPath('data.affiliate_commission_amount', 16)
            ->assertJsonPath('data.affiliate_commission_percent', 8);
    }
}
