<?php

namespace Tests\Feature;

use App\Constants\GeneralConstants;
use App\Models\AffiliateConversion;
use App\Models\AffiliateLinkClick;
use App\Models\AffiliatePayoutRequest;
use App\Models\Event;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\ScheduleTime;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeedsUserAffiliate;
use Tests\TestCase;

class AffiliatePartnerControllerTest extends TestCase
{
    use RefreshDatabase;
    use SeedsUserAffiliate;

    private User $customer;
    private User $partner;
    private Role $customerRole;
    private Event $event;
    private Schedule $schedule;
    private ScheduleTime $scheduleTime;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customerRole = Role::create([
            'name' => 'Customer',
            'code' => GeneralConstants::ROLES['CUSTOMER']['name'],
        ]);

        $this->customer = User::create([
            'role_uuid' => $this->customerRole->uuid,
            'email' => 'customer@test.com',
            'password' => bcrypt('password123'),
            'first_name' => 'Regular',
            'last_name' => 'Customer',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);

        $this->partner = User::create([
            'role_uuid' => $this->customerRole->uuid,
            'email' => 'partner@test.com',
            'password' => bcrypt('password123'),
            'first_name' => 'Affiliate',
            'last_name' => 'Partner',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);
        $this->seedApprovedAffiliate(
            $this->partner,
            'PARTNER1',
            now()->subDays(10),
            now()->subDays(5)
        );

        $this->event = Event::create([
            'event_name' => 'Affiliate Event',
            'event_description' => 'Test',
            'status' => 'published',
            'contact_email' => 'test@event.com',
            'event_type' => Event::EVENT_TYPES['SINGLE'],
            'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
            'event_config' => Event::EVENT_CONFIGS['SEAT_SELECTION'],
            'affiliate_enabled' => true,
            'affiliate_commission_percent' => 10,
        ]);

        $this->schedule = Schedule::create([
            'event_uuid' => $this->event->uuid,
            'date_from' => '2025-06-01',
            'date_to' => '2025-06-02',
            'status' => 'published',
        ]);

        $this->scheduleTime = ScheduleTime::create([
            'schedule_uuid' => $this->schedule->uuid,
            'time_start' => '10:00:00',
            'time_end' => '12:00:00',
            'status' => 'published',
        ]);
    }

    private function createConversion(float $orderTotal, float $commissionAmount): Transaction
    {
        $transaction = Transaction::create([
            'user_uuid' => $this->customer->uuid,
            'event_uuid' => $this->event->uuid,
            'schedule_uuid' => $this->schedule->uuid,
            'schedule_time_uuid' => $this->scheduleTime->uuid,
            'payment_method' => 'card',
            'order_number' => 'ORD-AFF-' . uniqid(),
            'total_amount' => $orderTotal,
            'sub_total' => $orderTotal,
            'tax_amount' => 0,
            'discount' => 0,
            'status' => 'completed',
            'payment_status' => Transaction::PAYMENT_STATUS['PAID'],
            'order_status' => 'confirmed',
            'affiliate_partner_uuid' => $this->partner->uuid,
        ]);

        $conversion = AffiliateConversion::create([
            'partner_user_uuid' => $this->partner->uuid,
            'transaction_uuid' => $transaction->uuid,
            'event_uuid' => $this->event->uuid,
            'order_total' => $orderTotal,
            'commission_percent' => 10,
            'commission_amount' => $commissionAmount,
        ]);

        DB::table('affiliate_conversions')->where('uuid', $conversion->uuid)->update([
            'created_at' => '2020-01-01 00:00:00',
            'updated_at' => '2020-01-01 00:00:00',
        ]);

        return $transaction;
    }

    // --- Show (dashboard) ---

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanShowAffiliateDashboard()
    {
        $response = $this->actingAs($this->partner, 'api')
            ->getJson('/api/v1/customer/affiliate');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'status',
                    'code',
                    'referral_link',
                    'affiliate_applied_at',
                    'affiliate_approved_at',
                    'stats' => [
                        'total_clicks',
                        'total_conversions',
                        'matured_commission_net',
                        'pending_earnings',
                        'paid_earnings',
                        'available_earnings',
                    ],
                    'bank_details' => [
                        'bank',
                        'branch',
                        'account_name',
                        'account_number',
                        'tin',
                    ],
                ],
            ]);

        $response->assertJson([
            'data' => [
                'status' => 'approved',
                'code' => 'PARTNER1',
            ],
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itShowsNoneStatusForNonAffiliateCustomer()
    {
        $response = $this->actingAs($this->customer, 'api')
            ->getJson('/api/v1/customer/affiliate');

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'none');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itRequiresAuthForDashboard()
    {
        $response = $this->getJson('/api/v1/customer/affiliate');
        $response->assertStatus(401);
    }

    // --- Apply ---

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanApplyAsAffiliatePartner()
    {
        $response = $this->actingAs($this->customer, 'api')
            ->postJson('/api/v1/customer/affiliate/apply');

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'approved');

        $this->customer->refresh();
        $this->customer->load('userAffiliate');
        $this->assertEquals(GeneralConstants::AFFILIATE_STATUSES['APPROVED'], $this->customer->userAffiliate->affiliate_status);
        $this->assertNotNull($this->customer->userAffiliate->affiliate_code);
        $this->assertNotNull($this->customer->userAffiliate->affiliate_applied_at);
        $this->assertNotNull($this->customer->userAffiliate->affiliate_approved_at);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itRejectsDoubleApplication()
    {
        $response = $this->actingAs($this->partner, 'api')
            ->postJson('/api/v1/customer/affiliate/apply');

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => 'You are already part of the affiliate program.']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itGeneratesUniqueAffiliateCode()
    {
        $this->actingAs($this->customer, 'api')
            ->postJson('/api/v1/customer/affiliate/apply');

        $this->customer->refresh();
        $this->customer->load('userAffiliate');
        $this->assertNotEmpty($this->customer->userAffiliate->affiliate_code);
        $this->assertNotEquals($this->partner->userAffiliate->affiliate_code, $this->customer->userAffiliate->affiliate_code);
    }

    // --- Bank details ---

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanUpdateBankDetails()
    {
        $response = $this->actingAs($this->partner, 'api')
            ->patchJson('/api/v1/customer/affiliate/bank-details', [
                'bank' => 'BDO',
                'branch' => 'Manila Main',
                'account_name' => 'Affiliate Partner',
                'account_number' => '1234567890',
                'tin' => '123-456-789-000',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.bank_details.bank', 'BDO')
            ->assertJsonPath('data.bank_details.branch', 'Manila Main')
            ->assertJsonPath('data.bank_details.account_name', 'Affiliate Partner')
            ->assertJsonPath('data.bank_details.account_number', '1234567890')
            ->assertJsonPath('data.bank_details.tin', '123-456-789-000');

        $this->assertDatabaseHas('user_affiliates', [
            'user_uuid' => $this->partner->uuid,
            'affiliate_bank_name' => 'BDO',
            'affiliate_bank_branch' => 'Manila Main',
            'affiliate_bank_account_name' => 'Affiliate Partner',
            'affiliate_bank_account_number' => '1234567890',
            'affiliate_bank_tin' => '123-456-789-000',
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itRejectsBankDetailsForNonApprovedAffiliate()
    {
        $response = $this->actingAs($this->customer, 'api')
            ->patchJson('/api/v1/customer/affiliate/bank-details', [
                'bank' => 'BDO',
                'branch' => 'Manila',
                'account_name' => 'Test',
                'account_number' => '123',
                'tin' => '000-000-000-000',
            ]);

        $response->assertStatus(403);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itValidatesBankDetailsFields()
    {
        $response = $this->actingAs($this->partner, 'api')
            ->patchJson('/api/v1/customer/affiliate/bank-details', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['bank', 'branch', 'account_name', 'account_number', 'tin']);
    }

    // --- Payout requests ---

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanCreatePayoutRequest()
    {
        $this->createConversion(15000, 1500);

        $response = $this->actingAs($this->partner, 'api')
            ->postJson('/api/v1/customer/affiliate/payout-requests', [
                'amount' => 1000,
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'uuid',
                    'user_uuid',
                    'amount_requested',
                    'currency',
                    'status',
                ],
            ]);

        $this->assertDatabaseHas('affiliate_payout_requests', [
            'user_uuid' => $this->partner->uuid,
            'status' => AffiliatePayoutRequest::STATUS_PENDING,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itRejectsPayoutIfAlreadyPending()
    {
        $this->createConversion(30000, 3000);

        AffiliatePayoutRequest::create([
            'user_uuid' => $this->partner->uuid,
            'amount_requested' => 1000,
            'currency' => 'PHP',
            'status' => AffiliatePayoutRequest::STATUS_PENDING,
        ]);

        $response = $this->actingAs($this->partner, 'api')
            ->postJson('/api/v1/customer/affiliate/payout-requests', [
                'amount' => 1000,
            ]);

        $response->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'You already have a pending payout request. Wait for it to be processed.',
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itRejectsPayoutForNonApprovedAffiliate()
    {
        $response = $this->actingAs($this->customer, 'api')
            ->postJson('/api/v1/customer/affiliate/payout-requests', [
                'amount' => 1000,
            ]);

        $response->assertStatus(403);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itRejectsPayoutBelowMinimumAvailableEarnings()
    {
        $response = $this->actingAs($this->partner, 'api')
            ->postJson('/api/v1/customer/affiliate/payout-requests', [
                'amount' => 1000,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanListPayoutHistory()
    {
        AffiliatePayoutRequest::create([
            'user_uuid' => $this->partner->uuid,
            'amount_requested' => 1500,
            'currency' => 'PHP',
            'status' => AffiliatePayoutRequest::STATUS_APPROVED,
            'processed_at' => now(),
        ]);

        $response = $this->actingAs($this->partner, 'api')
            ->getJson('/api/v1/customer/affiliate/payout-requests');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'approved');
    }

    // --- Stats ---

    #[\PHPUnit\Framework\Attributes\Test]
    public function itReturnsAccurateStats()
    {
        AffiliateLinkClick::create([
            'partner_user_uuid' => $this->partner->uuid,
            'ref_code' => 'PARTNER1',
            'path' => '/events/test',
        ]);
        AffiliateLinkClick::create([
            'partner_user_uuid' => $this->partner->uuid,
            'ref_code' => 'PARTNER1',
            'path' => '/browse',
        ]);

        $this->createConversion(5000, 500);

        AffiliatePayoutRequest::create([
            'user_uuid' => $this->partner->uuid,
            'amount_requested' => 200,
            'currency' => 'PHP',
            'status' => AffiliatePayoutRequest::STATUS_APPROVED,
            'processed_at' => now(),
        ]);

        $response = $this->actingAs($this->partner, 'api')
            ->getJson('/api/v1/customer/affiliate');

        $response->assertStatus(200);
        $stats = $response->json('data.stats');

        $this->assertEquals(2, $stats['total_clicks']);
        $this->assertEquals(450, $stats['total_conversions']);
        $this->assertEquals(450, $stats['matured_commission_net']);
        $this->assertEquals(200, $stats['paid_earnings']);
        $this->assertEquals(0, $stats['pending_earnings']);
        $this->assertEquals(250, $stats['available_earnings']);
    }

    // --- Conversion history ---

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanListConversionHistory()
    {
        $this->createConversion(5000, 500);
        $this->createConversion(3000, 300);

        $response = $this->actingAs($this->partner, 'api')
            ->getJson('/api/v1/customer/affiliate/conversions');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'uuid',
                        'partner_user_uuid',
                        'transaction_uuid',
                        'entry_type',
                        'ticket_uuid',
                        'event_uuid',
                        'order_total',
                        'commission_percent',
                        'commission_amount',
                        'created_at',
                        'updated_at',
                        'event' => ['uuid', 'event_name'],
                        'transaction' => ['uuid', 'order_number', 'paid_at'],
                    ],
                ],
                'meta',
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itReturnsConversionHistoryWithCorrectData()
    {
        $this->createConversion(10000, 1000);

        $response = $this->actingAs($this->partner, 'api')
            ->getJson('/api/v1/customer/affiliate/conversions');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $conversion = $response->json('data.0');
        $this->assertEquals(10000, $conversion['order_total']);
        $this->assertEquals(10, $conversion['commission_percent']);
        $this->assertEquals(1000, $conversion['commission_amount']);
        $this->assertEquals('credit', $conversion['entry_type']);
        $this->assertEquals('Affiliate Event', $conversion['event']['event_name']);
        $this->assertNotNull($conversion['transaction']['order_number']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itRejectsConversionHistoryForNonApprovedAffiliate()
    {
        $response = $this->actingAs($this->customer, 'api')
            ->getJson('/api/v1/customer/affiliate/conversions');

        $response->assertStatus(403);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itRequiresAuthForConversionHistory()
    {
        $response = $this->getJson('/api/v1/customer/affiliate/conversions');
        $response->assertStatus(401);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itPaginatesConversionHistory()
    {
        for ($i = 0; $i < 12; $i++) {
            $this->createConversion(1000 + $i, 100 + $i);
        }

        $response = $this->actingAs($this->partner, 'api')
            ->getJson('/api/v1/customer/affiliate/conversions?per_page=5');

        $response->assertStatus(200);
        $this->assertCount(5, $response->json('data'));
        $this->assertGreaterThan(1, $response->json('meta.last_page'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itReturnsEmptyConversionHistoryWhenNoneExist()
    {
        $response = $this->actingAs($this->partner, 'api')
            ->getJson('/api/v1/customer/affiliate/conversions');

        $response->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    // --- Available events ---

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanListAvailableEventsForAffiliate()
    {
        $response = $this->actingAs($this->partner, 'api')
            ->getJson('/api/v1/customer/affiliate/available-events');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data',
                'meta',
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itRejectsAvailableEventsForNonApprovedAffiliate()
    {
        $response = $this->actingAs($this->customer, 'api')
            ->getJson('/api/v1/customer/affiliate/available-events');

        $response->assertStatus(403);
    }
}
