<?php

namespace Tests\Feature;

use App\Constants\GeneralConstants;
use App\Models\Event;
use App\Models\MerchantPayoutRequest;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\Schedule;
use App\Models\ScheduleTime;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAdminWithOrganizationPermissions;
use Tests\Concerns\CreatesMerchantPayoutTestData;
use Tests\TestCase;

class AdminOrganizationAccountingControllerTest extends TestCase
{
    use CreatesAdminWithOrganizationPermissions;
    use CreatesMerchantPayoutTestData;
    use RefreshDatabase;

    private Organization $organization;

    private Organization $otherOrganization;

    private Event $eventA;

    private Event $eventB;

    private Event $otherOrgEvent;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        // Freeze time before the May-16–end payout release (15th of next month).
        Carbon::setTestNow(Carbon::parse('2026-05-20 12:00:00'));

        $this->setUpAdminWithOrganizationPermissions();

        $this->organization = Organization::create([
            'name' => 'Test Merchant',
            'representative_first_name' => 'Jane',
            'representative_last_name' => 'Doe',
            'address' => '123 Test Street',
            'contact_number' => '09171234567',
            'email' => 'merchant@test.com',
            'status' => GeneralConstants::ORGANIZER_STATUSES['APPROVED'],
            'commission_percentage' => 10,
        ]);

        $this->otherOrganization = Organization::create([
            'name' => 'Other Merchant',
            'representative_first_name' => 'John',
            'representative_last_name' => 'Smith',
            'email' => 'other@test.com',
            'status' => GeneralConstants::ORGANIZER_STATUSES['APPROVED'],
            'commission_percentage' => 10,
        ]);

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

        $this->createOrganizationBank($this->organization);
        $this->createOrganizationBank($this->otherOrganization);

        $this->eventA = $this->createEvent($this->organization, 'Event Alpha');
        $this->eventB = $this->createEvent($this->organization, 'Event Beta');
        $this->otherOrgEvent = $this->createEvent($this->otherOrganization, 'Other Org Event');

        // Matured: paid 1st–15th → available on 30th of same month.
        $this->createPaidTransaction($this->eventA, 1000, Carbon::parse('2020-06-01 12:00:00'));

        // Pending: paid 16th–end → available 15th of next month.
        $this->createPaidTransaction($this->eventB, 2000, Carbon::parse('2026-05-17 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itReturnsAccountingSummaryForAllEvents(): void
    {
        $response = $this->authedGet(
            '/api/v1/organizations/' . $this->organization->uuid . '/accounting/summary',
        );

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.available', 900)
            ->assertJsonPath('data.pending', 1800)
            ->assertJsonPath('data.commission_percentage', 10);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itFiltersAccountingSummaryByEventUuid(): void
    {
        $response = $this->authedGet(
            '/api/v1/organizations/' . $this->organization->uuid . '/accounting/summary',
            ['event_uuid' => $this->eventA->uuid],
        );

        $response->assertStatus(200)
            ->assertJsonPath('data.available', 900)
            ->assertJsonPath('data.pending', 0)
            ->assertJsonPath('data.total_cashout', 0);

        $response = $this->authedGet(
            '/api/v1/organizations/' . $this->organization->uuid . '/accounting/summary',
            ['event_uuid' => $this->eventB->uuid],
        );

        $response->assertStatus(200)
            ->assertJsonPath('data.available', 0)
            ->assertJsonPath('data.pending', 1800);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCalculatesCommissionOnGrossMinusTaxAndFees(): void
    {
        $event = $this->createEvent($this->organization, 'Taxed Event');
        $this->createPaidTransaction($event, 1120, Carbon::parse('2026-05-18 12:00:00'), 120);

        $response = $this->authedGet(
            '/api/v1/organizations/' . $this->organization->uuid . '/accounting/remittance-buckets',
            [
                'bucket' => 'pending',
                'event_uuid' => $event->uuid,
            ],
        );

        $response->assertStatus(200)
            ->assertJsonPath('data.totals.transaction_count', 1)
            ->assertJsonPath('data.totals.merchant_net_sum', 900);

        $tx = $response->json('data.months.0.release_15.transactions.0');
        $this->assertSame(1000.0, (float) $tx['net_selling_price']);
        $this->assertSame(100.0, (float) $tx['platform_fee']);
        $this->assertSame(900.0, (float) $tx['total_payout']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itFiltersRemittanceBucketsByEventUuid(): void
    {
        $response = $this->authedGet(
            '/api/v1/organizations/' . $this->organization->uuid . '/accounting/remittance-buckets',
            [
                'bucket' => 'available',
                'event_uuid' => $this->eventA->uuid,
            ],
        );

        $response->assertStatus(200)
            ->assertJsonPath('data.bucket', 'available')
            ->assertJsonPath('data.totals.transaction_count', 1)
            ->assertJsonPath('data.totals.merchant_net_sum', 900);

        $response = $this->authedGet(
            '/api/v1/organizations/' . $this->organization->uuid . '/accounting/remittance-buckets',
            [
                'bucket' => 'pending',
                'event_uuid' => $this->eventB->uuid,
            ],
        );

        $response->assertStatus(200)
            ->assertJsonPath('data.bucket', 'pending')
            ->assertJsonPath('data.totals.transaction_count', 1)
            ->assertJsonPath('data.totals.merchant_net_sum', 1800);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itReturnsEmptyPayoutRequestsWhenFilteredByEvent(): void
    {
        MerchantPayoutRequest::query()->create($this->merchantPayoutAttributes($this->organization, [
            'amount_requested' => 100,
            'status' => MerchantPayoutRequest::STATUS_PENDING,
            'requested_by_admin_uuid' => $this->adminUser->uuid,
        ]));

        $all = $this->authedGet(
            '/api/v1/organizations/' . $this->organization->uuid . '/accounting/payout-requests',
        );

        $all->assertStatus(200)
            ->assertJsonPath('data.pending.meta.total', 1)
            ->assertJsonCount(1, 'data.pending.rows');

        $filtered = $this->authedGet(
            '/api/v1/organizations/' . $this->organization->uuid . '/accounting/payout-requests',
            ['event_uuid' => $this->eventA->uuid],
        );

        $filtered->assertStatus(200)
            ->assertJsonPath('data.pending.rows', [])
            ->assertJsonPath('data.success.rows', [])
            ->assertJsonPath('data.declined.rows', []);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itExcludesVoidedRemittancesFromAvailableBalance(): void
    {
        $remittance = MerchantPayoutRequest::query()->create($this->merchantPayoutAttributes($this->organization, [
            'event_uuid' => $this->eventA->uuid,
            'amount_requested' => 500,
            'status' => MerchantPayoutRequest::STATUS_APPROVED,
            'processed_at' => now(),
        ]));

        $this->authedGet(
            '/api/v1/organizations/' . $this->organization->uuid . '/accounting/summary',
            ['event_uuid' => $this->eventA->uuid],
        )
            ->assertStatus(200)
            ->assertJsonPath('data.available', 400)
            ->assertJsonPath('data.total_cashout', 500);

        $remittance->update([
            'status' => MerchantPayoutRequest::STATUS_VOID,
            'void_at' => now(),
            'void_by_uuid' => $this->adminUser->uuid,
        ]);

        $this->authedGet(
            '/api/v1/organizations/' . $this->organization->uuid . '/accounting/summary',
            ['event_uuid' => $this->eventA->uuid],
        )
            ->assertStatus(200)
            ->assertJsonPath('data.available', 900)
            ->assertJsonPath('data.total_cashout', 0);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itMatchesAllEventsAvailableToSumOfPerEventBalances(): void
    {
        MerchantPayoutRequest::query()->create($this->merchantPayoutAttributes($this->organization, [
            'event_uuid' => $this->eventB->uuid,
            'amount_requested' => 500,
            'status' => MerchantPayoutRequest::STATUS_APPROVED,
            'processed_at' => now(),
        ]));

        $this->authedGet(
            '/api/v1/organizations/' . $this->organization->uuid . '/accounting/summary',
            ['event_uuid' => $this->eventA->uuid],
        )
            ->assertStatus(200)
            ->assertJsonPath('data.available', 900);

        $this->authedGet(
            '/api/v1/organizations/' . $this->organization->uuid . '/accounting/summary',
            ['event_uuid' => $this->eventB->uuid],
        )
            ->assertStatus(200)
            ->assertJsonPath('data.available', -500)
            ->assertJsonPath('data.total_cashout', 500);

        $this->authedGet(
            '/api/v1/organizations/' . $this->organization->uuid . '/accounting/summary',
        )
            ->assertStatus(200)
            ->assertJsonPath('data.available', 400);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itReportsTotalCashoutPerEventWhenFiltered(): void
    {
        MerchantPayoutRequest::query()->create($this->merchantPayoutAttributes($this->organization, [
            'event_uuid' => $this->eventA->uuid,
            'amount_requested' => 350,
            'status' => MerchantPayoutRequest::STATUS_APPROVED,
            'processed_at' => now(),
        ]));

        MerchantPayoutRequest::query()->create($this->merchantPayoutAttributes($this->organization, [
            'event_uuid' => $this->eventB->uuid,
            'amount_requested' => 125,
            'status' => MerchantPayoutRequest::STATUS_APPROVED,
            'processed_at' => now(),
        ]));

        $this->authedGet(
            '/api/v1/organizations/' . $this->organization->uuid . '/accounting/summary',
            ['event_uuid' => $this->eventA->uuid],
        )
            ->assertStatus(200)
            ->assertJsonPath('data.total_cashout', 350);

        $this->authedGet(
            '/api/v1/organizations/' . $this->organization->uuid . '/accounting/summary',
            ['event_uuid' => $this->eventB->uuid],
        )
            ->assertStatus(200)
            ->assertJsonPath('data.total_cashout', 125);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itShowsNegativeAvailableWhenAdvancePayoutExceedsMaturedRevenue(): void
    {
        MerchantPayoutRequest::query()->create($this->merchantPayoutAttributes($this->organization, [
            'event_uuid' => $this->eventA->uuid,
            'amount_requested' => 1200,
            'status' => MerchantPayoutRequest::STATUS_APPROVED,
            'processed_at' => now(),
        ]));

        $this->authedGet(
            '/api/v1/organizations/' . $this->organization->uuid . '/accounting/summary',
            ['event_uuid' => $this->eventA->uuid],
        )
            ->assertStatus(200)
            ->assertJsonPath('data.available', -300);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itDeductsOrganizationWidePayoutsFromAllEventsAvailable(): void
    {
        MerchantPayoutRequest::query()->create($this->merchantPayoutAttributes($this->organization, [
            'amount_requested' => 500,
            'status' => MerchantPayoutRequest::STATUS_APPROVED,
            'processed_at' => now(),
        ]));

        $this->authedGet(
            '/api/v1/organizations/' . $this->organization->uuid . '/accounting/summary',
            ['event_uuid' => $this->eventA->uuid],
        )
            ->assertStatus(200)
            ->assertJsonPath('data.available', 900);

        $this->authedGet(
            '/api/v1/organizations/' . $this->organization->uuid . '/accounting/summary',
        )
            ->assertStatus(200)
            ->assertJsonPath('data.available', 400);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itPaginatesPayoutRequestsByStatus(): void
    {
        for ($i = 0; $i < 6; $i++) {
            MerchantPayoutRequest::query()->create($this->merchantPayoutAttributes($this->organization, [
                'amount_requested' => 50 + $i,
                'status' => MerchantPayoutRequest::STATUS_PENDING,
            ]));
        }

        $response = $this->authedGet(
            '/api/v1/organizations/' . $this->organization->uuid . '/accounting/payout-requests',
            [
                'pending_page' => 1,
                'per_page' => 10,
            ],
        );

        $response->assertStatus(200)
            ->assertJsonPath('data.pending.meta.per_page', 10)
            ->assertJsonPath('data.pending.meta.total', 6)
            ->assertJsonCount(6, 'data.pending.rows');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itValidatesEventUuidBelongsToOrganization(): void
    {
        $response = $this->authedGet(
            '/api/v1/organizations/' . $this->organization->uuid . '/accounting/summary',
            ['event_uuid' => $this->otherOrgEvent->uuid],
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['event_uuid']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itRequiresBucketForRemittanceBuckets(): void
    {
        $response = $this->authedGet(
            '/api/v1/organizations/' . $this->organization->uuid . '/accounting/remittance-buckets',
        );

        $response->assertStatus(422)
            ->assertJsonPath(
                'message',
                'Query parameter "bucket" is required and must be "available" or "pending".',
            );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itRequiresAuthentication(): void
    {
        $this->getJson('/api/v1/organizations/' . $this->organization->uuid . '/accounting/summary')
            ->assertStatus(401);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itRequiresOrganizationsViewPermission(): void
    {
        $role = Role::create([
            'name' => 'No Org Access',
            'code' => 'no-org-access',
        ]);

        $permission = Permission::query()->where('code', 'organizations')->first();

        $viewer = \App\Models\AdminUser::create([
            'role_uuid' => $role->uuid,
            'email' => 'viewer@test.com',
            'password' => 'password123',
            'first_name' => 'View',
            'last_name' => 'Only',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);

        $token = auth('admin')->login($viewer) ?? '';

        $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->getJson('/api/v1/organizations/' . $this->organization->uuid . '/accounting/summary')
            ->assertStatus(403);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function authedGet(string $uri, array $query = []): \Illuminate\Testing\TestResponse
    {
        $url = $query === [] ? $uri : $uri . '?' . http_build_query($query);

        return $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->getJson($url);
    }

    private function createEvent(Organization $organization, string $name): Event
    {
        $event = Event::create([
            'event_name' => $name,
            'event_description' => 'Test',
            'contact_email' => strtolower(str_replace(' ', '', $name)) . '@test.com',
            'event_type' => Event::EVENT_TYPES['SINGLE'],
            'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
            'event_config' => Event::EVENT_CONFIGS['OPEN_TICKET'],
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'organization_uuid' => $organization->uuid,
        ]);

        $schedule = Schedule::create([
            'event_uuid' => $event->uuid,
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-02',
            'status' => 'published',
        ]);

        ScheduleTime::create([
            'schedule_uuid' => $schedule->uuid,
            'time_start' => '10:00:00',
            'time_end' => '12:00:00',
            'status' => 'published',
        ]);

        return $event;
    }

    private function createPaidTransaction(
        Event $event,
        float $amount,
        Carbon $paidAt,
        float $taxAmount = 0,
    ): Transaction {
        $schedule = Schedule::query()->where('event_uuid', $event->uuid)->firstOrFail();
        $scheduleTime = ScheduleTime::query()->where('schedule_uuid', $schedule->uuid)->firstOrFail();

        return Transaction::create([
            'user_uuid' => $this->customer->uuid,
            'event_uuid' => $event->uuid,
            'schedule_uuid' => $schedule->uuid,
            'schedule_time_uuid' => $scheduleTime->uuid,
            'organization_uuid' => $event->organization_uuid,
            'order_number' => 'ORD-' . uniqid('', true),
            'total_amount' => $amount,
            'sub_total' => round($amount - $taxAmount, 2),
            'tax_amount' => $taxAmount,
            'discount' => 0,
            'status' => Transaction::STATUS['ACTIVE'],
            'payment_status' => Transaction::PAYMENT_STATUS['PAID'],
            'order_status' => Transaction::ORDER_STATUS['CONFIRMED'],
            'paid_at' => $paidAt,
        ]);
    }
}
