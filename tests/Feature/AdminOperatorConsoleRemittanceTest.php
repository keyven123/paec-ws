<?php

namespace Tests\Feature;

use App\Constants\GeneralConstants;
use App\Models\AdminUser;
use App\Models\Event;
use App\Models\MerchantPayoutRequest;
use App\Models\Organization;
use App\Models\OrganizationBank;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\Schedule;
use App\Models\ScheduleTime;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesMerchantPayoutTestData;
use Tests\TestCase;

class AdminOperatorConsoleRemittanceTest extends TestCase
{
    use CreatesMerchantPayoutTestData;
    use RefreshDatabase;

    private AdminUser $adminUser;

    private string $adminToken;

    private Organization $organization;

    private Event $event;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::create([
            'name' => 'Platform Admin',
            'code' => GeneralConstants::ROLES['ADMIN']['name'],
            'is_admin' => true,
        ]);

        $financePermission = Permission::create([
            'name' => 'Finance',
            'code' => 'finance',
            'available_access' => ['view'],
        ]);

        RolePermission::create([
            'role_uuid' => $role->uuid,
            'permission_uuid' => $financePermission->uuid,
            'access' => 'finance-view',
        ]);

        $this->adminUser = AdminUser::create([
            'role_uuid' => $role->uuid,
            'email' => 'finance-admin@test.com',
            'password' => 'password123',
            'first_name' => 'Finance',
            'last_name' => 'Admin',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);

        $this->adminToken = auth('admin')->login($this->adminUser) ?? '';

        $this->organization = Organization::create([
            'name' => 'Remit Merchant',
            'representative_first_name' => 'Jane',
            'representative_last_name' => 'Doe',
            'email' => 'remit@test.com',
            'status' => GeneralConstants::ORGANIZER_STATUSES['APPROVED'],
            'commission_percentage' => 10,
        ]);

        $this->createOrganizationBank($this->organization);

        $customerRole = Role::create([
            'name' => 'Customer',
            'code' => GeneralConstants::ROLES['CUSTOMER']['name'],
        ]);

        $this->customer = User::create([
            'role_uuid' => $customerRole->uuid,
            'email' => 'customer@remit.test',
            'password' => 'password123',
            'first_name' => 'Test',
            'last_name' => 'Customer',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);

        $this->event = Event::create([
            'event_name' => 'Remit Event',
            'event_description' => 'Test',
            'contact_email' => 'event@remit.test',
            'event_type' => Event::EVENT_TYPES['SINGLE'],
            'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
            'event_config' => Event::EVENT_CONFIGS['OPEN_TICKET'],
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'organization_uuid' => $this->organization->uuid,
        ]);

        $this->createMaturedPaidTransaction($this->event, 2000);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itListsRemittanceEventsForMerchantWithSearch(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->getJson('/api/v1/admin/finance/operator-console/events?' . http_build_query([
            'organization_uuid' => $this->organization->uuid,
            'q' => 'Remit',
        ]));

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonFragment([
                'uuid' => $this->event->uuid,
                'event_name' => 'Remit Event',
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itStoresRemittanceWithEventUuid(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->postJson('/api/v1/admin/finance/operator-console/remittances', [
            'organization_uuid' => $this->organization->uuid,
            'event_uuid' => $this->event->uuid,
            'amount' => 1500,
            'note' => 'Manual payout for event',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('merchant_payout_requests', [
            'organization_uuid' => $this->organization->uuid,
            'event_uuid' => $this->event->uuid,
            'amount_requested' => '1500.00',
            'status' => MerchantPayoutRequest::STATUS_APPROVED,
        ]);

        $this->assertNotNull(
            MerchantPayoutRequest::query()
                ->where('organization_uuid', $this->organization->uuid)
                ->where('event_uuid', $this->event->uuid)
                ->value('organization_bank_uuid'),
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itRejectsEventUuidFromAnotherOrganization(): void
    {
        $otherOrg = Organization::create([
            'name' => 'Other Merchant',
            'representative_first_name' => 'John',
            'representative_last_name' => 'Smith',
            'email' => 'other@remit.test',
            'status' => GeneralConstants::ORGANIZER_STATUSES['APPROVED'],
        ]);

        $otherEvent = Event::create([
            'event_name' => 'Other Event',
            'event_description' => 'Test',
            'contact_email' => 'other-event@remit.test',
            'event_type' => Event::EVENT_TYPES['SINGLE'],
            'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
            'event_config' => Event::EVENT_CONFIGS['OPEN_TICKET'],
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'organization_uuid' => $otherOrg->uuid,
        ]);

        $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->postJson('/api/v1/admin/finance/operator-console/remittances', [
            'organization_uuid' => $this->organization->uuid,
            'event_uuid' => $otherEvent->uuid,
            'amount' => 500,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['event_uuid']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itRejectsAmountExceedingAvailableBalanceForEvent(): void
    {
        $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->postJson('/api/v1/admin/finance/operator-console/remittances', [
            'organization_uuid' => $this->organization->uuid,
            'event_uuid' => $this->event->uuid,
            'amount' => 999_999.99,
        ])->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'Amount exceeds available balance for this event (PHP 1,800.00).',
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itPaginatesRemittanceList(): void
    {
        for ($i = 0; $i < 6; $i++) {
            MerchantPayoutRequest::query()->create($this->merchantPayoutAttributes($this->organization, [
                'event_uuid' => $this->event->uuid,
                'amount_requested' => 100 + $i,
                'status' => MerchantPayoutRequest::STATUS_APPROVED,
                'processed_at' => now()->subMinutes($i),
            ]));
        }

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->getJson('/api/v1/admin/finance/operator-console/remittances?' . http_build_query([
            'page' => 1,
            'per_page' => 5,
        ]));

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.meta.current_page', 1)
            ->assertJsonPath('data.meta.per_page', 5)
            ->assertJsonPath('data.meta.total', 6)
            ->assertJsonPath('data.meta.last_page', 2)
            ->assertJsonCount(5, 'data.rows');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itPaginatesPendingPayouts(): void
    {
        for ($i = 0; $i < 6; $i++) {
            MerchantPayoutRequest::query()->create($this->merchantPayoutAttributes($this->organization, [
                'amount_requested' => 50 + $i,
                'status' => MerchantPayoutRequest::STATUS_PENDING,
                'merchant_note' => 'Pending '.$i,
            ]));
        }

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->getJson('/api/v1/admin/finance/operator-console/pending-payouts?' . http_build_query([
            'page' => 1,
            'per_page' => 10,
        ]));

        $response->assertStatus(200)
            ->assertJsonPath('data.meta.per_page', 10)
            ->assertJsonPath('data.meta.total', 6)
            ->assertJsonCount(6, 'data.rows');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itIncludesPreferredBankDetailsOnPendingPayouts(): void
    {
        $bank = $this->createOrganizationBank($this->organization, [
            'bank_name' => 'BPI',
            'bank_branch' => 'Makati',
            'bank_address' => 'Ayala Ave',
            'bank_account_name' => 'Remit Merchant Inc',
            'bank_account_number' => '9876543210',
            'is_default' => false,
        ]);

        MerchantPayoutRequest::query()->create($this->merchantPayoutAttributes($this->organization, [
            'event_uuid' => $this->event->uuid,
            'organization_bank_uuid' => $bank->uuid,
            'amount_requested' => 2500,
            'status' => MerchantPayoutRequest::STATUS_PENDING,
            'merchant_note' => 'Q1 settlement',
        ]));

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->getJson('/api/v1/admin/finance/operator-console/pending-payouts?page=1&per_page=10');

        $response->assertStatus(200)
            ->assertJsonPath('data.rows.0.event', $this->event->event_name)
            ->assertJsonPath('data.rows.0.merchant_note', 'Q1 settlement')
            ->assertJsonPath('data.rows.0.bank.uuid', $bank->uuid)
            ->assertJsonPath('data.rows.0.bank.bank_name', 'BPI')
            ->assertJsonPath('data.rows.0.bank.bank_branch', 'Makati')
            ->assertJsonPath('data.rows.0.bank.bank_address', 'Ayala Ave')
            ->assertJsonPath('data.rows.0.bank.bank_account_name', 'Remit Merchant Inc')
            ->assertJsonPath('data.rows.0.bank.bank_account_number', '9876543210')
            ->assertJsonPath('data.rows.0.bank.status', OrganizationBank::STATUS_ACTIVE);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itPaginatesPayoutRequestsByStatus(): void
    {
        MerchantPayoutRequest::query()->create($this->merchantPayoutAttributes($this->organization, [
            'amount_requested' => 200,
            'status' => MerchantPayoutRequest::STATUS_DECLINED,
            'processed_at' => now(),
        ]));

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->getJson('/api/v1/admin/finance/operator-console/payout-requests?' . http_build_query([
            'status' => 'declined',
            'page' => 1,
            'per_page' => 10,
        ]));

        $response->assertStatus(200)
            ->assertJsonPath('data.meta.per_page', 10)
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonCount(1, 'data.rows');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itVoidsApprovedRemittance(): void
    {
        $remittance = MerchantPayoutRequest::query()->create($this->merchantPayoutAttributes($this->organization, [
            'event_uuid' => $this->event->uuid,
            'amount_requested' => 500,
            'status' => MerchantPayoutRequest::STATUS_APPROVED,
            'processed_at' => now(),
            'processed_by_uuid' => $this->adminUser->uuid,
        ]));

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->patchJson('/api/v1/admin/finance/operator-console/remittances/' . $remittance->uuid . '/void');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', MerchantPayoutRequest::STATUS_VOID)
            ->assertJsonPath('data.void_by_uuid', $this->adminUser->uuid);

        $remittance->refresh();
        $this->assertSame(MerchantPayoutRequest::STATUS_VOID, $remittance->status);
        $this->assertNotNull($remittance->void_at);
        $this->assertSame($this->adminUser->uuid, $remittance->void_by_uuid);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itRejectsVoidingNonApprovedRemittance(): void
    {
        $pending = MerchantPayoutRequest::query()->create($this->merchantPayoutAttributes($this->organization, [
            'amount_requested' => 100,
            'status' => MerchantPayoutRequest::STATUS_PENDING,
        ]));

        $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->patchJson('/api/v1/admin/finance/operator-console/remittances/' . $pending->uuid . '/void')
            ->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'Only approved remittance entries can be voided.',
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itIncludesApprovedAndVoidRemittancesInList(): void
    {
        $voided = MerchantPayoutRequest::query()->create($this->merchantPayoutAttributes($this->organization, [
            'event_uuid' => $this->event->uuid,
            'amount_requested' => 300,
            'status' => MerchantPayoutRequest::STATUS_VOID,
            'void_at' => now(),
            'void_by_uuid' => $this->adminUser->uuid,
        ]));

        $active = MerchantPayoutRequest::query()->create($this->merchantPayoutAttributes($this->organization, [
            'event_uuid' => $this->event->uuid,
            'amount_requested' => 400,
            'status' => MerchantPayoutRequest::STATUS_APPROVED,
            'processed_at' => now(),
        ]));

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->getJson('/api/v1/admin/finance/operator-console/remittances');

        $response->assertStatus(200)
            ->assertJsonPath('data.meta.total', 2)
            ->assertJsonCount(2, 'data.rows')
            ->assertJsonFragment([
                'uuid' => $active->uuid,
                'status' => MerchantPayoutRequest::STATUS_APPROVED,
                'status_label' => 'Approved',
            ])
            ->assertJsonFragment([
                'uuid' => $voided->uuid,
                'status' => MerchantPayoutRequest::STATUS_VOID,
                'status_label' => 'Void',
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itFiltersRemittancesByOrganizationUuid(): void
    {
        $otherOrg = Organization::create([
            'name' => 'Other Remit Merchant',
            'representative_first_name' => 'John',
            'representative_last_name' => 'Smith',
            'email' => 'other-remit@test.com',
            'status' => GeneralConstants::ORGANIZER_STATUSES['APPROVED'],
        ]);

        $this->createOrganizationBank($otherOrg);

        MerchantPayoutRequest::query()->create($this->merchantPayoutAttributes($this->organization, [
            'event_uuid' => $this->event->uuid,
            'amount_requested' => 200,
            'status' => MerchantPayoutRequest::STATUS_APPROVED,
            'processed_at' => now(),
        ]));

        MerchantPayoutRequest::query()->create($this->merchantPayoutAttributes($otherOrg, [
            'amount_requested' => 999,
            'status' => MerchantPayoutRequest::STATUS_APPROVED,
            'processed_at' => now(),
        ]));

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->getJson('/api/v1/admin/finance/operator-console/remittances?' . http_build_query([
            'organization_uuid' => $this->organization->uuid,
        ]));

        $response->assertStatus(200)
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonCount(1, 'data.rows')
            ->assertJsonPath('data.rows.0.organizer', 'Remit Merchant');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itRequiresEventUuidWhenCreatingRemittance(): void
    {
        $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->postJson('/api/v1/admin/finance/operator-console/remittances', [
            'organization_uuid' => $this->organization->uuid,
            'amount' => 500,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['event_uuid']);
    }

    private function createMaturedPaidTransaction(Event $event, float $amount): Transaction
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

        return Transaction::create([
            'user_uuid' => $this->customer->uuid,
            'event_uuid' => $event->uuid,
            'schedule_uuid' => $schedule->uuid,
            'schedule_time_uuid' => $scheduleTime->uuid,
            'organization_uuid' => $event->organization_uuid,
            'order_number' => 'ORD-' . uniqid('', true),
            'total_amount' => $amount,
            'sub_total' => $amount,
            'tax_amount' => 0,
            'discount' => 0,
            'status' => Transaction::STATUS['ACTIVE'],
            'payment_status' => Transaction::PAYMENT_STATUS['PAID'],
            'order_status' => Transaction::ORDER_STATUS['CONFIRMED'],
            'paid_at' => Carbon::parse('2020-06-01 12:00:00'),
        ]);
    }
}
