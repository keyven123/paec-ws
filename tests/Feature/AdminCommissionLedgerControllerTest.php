<?php

namespace Tests\Feature;

use App\Constants\GeneralConstants;
use App\Models\AdminUser;
use App\Models\Dataset;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\ScheduleTime;
use App\Models\Transaction;
use App\Models\TransactionCommission;
use App\Models\User;
use App\Services\Platform\TransactionCommissionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifies the GET /admin/finance/commission-ledger endpoint:
 *  - aggregates transaction_commissions over a date range
 *  - groups totals correctly by provider and by (provider, method)
 *  - exposes the "cash in <provider>" derived metric
 *  - validates from/to inputs
 *
 * The seeded fixtures mirror the DatasetSeeder rates so amounts are
 * reproducible and easy to verify by hand.
 */
class AdminCommissionLedgerControllerTest extends TestCase
{
    use RefreshDatabase;

    private string $token;
    private User $customer;
    private Event $event;
    private Organization $organization;
    private Schedule $schedule;
    private ScheduleTime $scheduleTime;

    protected function setUp(): void
    {
        parent::setUp();

        $superAdminRole = Role::create([
            'name' => 'Super Admin',
            'code' => GeneralConstants::ROLES['SUPER_ADMIN']['name'],
            'is_admin' => true,
        ]);

        $admin = AdminUser::create([
            'role_uuid' => $superAdminRole->uuid,
            'email' => 'finance-admin@test.com',
            'password' => 'password123',
            'first_name' => 'Fin',
            'last_name' => 'Admin',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);
        $this->token = auth('admin')->login($admin) ?? '';

        $customerRole = Role::create([
            'name' => 'Customer',
            'code' => GeneralConstants::ROLES['CUSTOMER']['name'],
        ]);
        $this->customer = User::factory()->create(['role_uuid' => $customerRole->uuid]);

        $this->organization = Organization::create([
            'name' => 'Ledger Org',
            'representative_first_name' => 'Rep',
            'email' => 'org-ledger@test.com',
            'status' => 'approved',
        ]);

        $this->event = Event::create([
            'event_name' => 'Ledger Event',
            'event_description' => 'Test',
            'contact_email' => 'e@test.com',
            'event_type' => Event::EVENT_TYPES['SINGLE'],
            'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
            'event_config' => Event::EVENT_CONFIGS['OPEN_TICKET'],
            'tags' => [],
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'ticket_sold' => 0,
            'organization_uuid' => $this->organization->uuid,
        ]);
        $this->schedule = Schedule::create([
            'event_uuid' => $this->event->uuid,
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-02',
            'status' => 'published',
        ]);
        $this->scheduleTime = ScheduleTime::create([
            'schedule_uuid' => $this->schedule->uuid,
            'time_start' => '10:00:00',
            'time_end' => '12:00:00',
            'status' => 'published',
        ]);

        Dataset::create(['name' => 'merchant_commission_percentage', 'value' => '10']);
        Dataset::create([
            'name' => 'paymongo_payment_rates',
            'value' => json_encode([
                ['name' => 'qrph', 'value' => 1.34],
                ['name' => 'card', 'value' => 2.9],
                ['name' => 'gcash', 'value' => 2.23],
                ['name' => 'grab_pay', 'value' => 1.96],
                ['name' => 'shopee_pay', 'value' => 1.7],
                ['name' => 'billease', 'value' => 1.34],
                ['name' => 'paymaya', 'value' => 1.79],
                ['name' => 'dob', 'value' => 1.29],
                ['name' => 'dob_fixed_minimum', 'value' => 0],
                ['name' => 'brankas', 'value' => 1.34],
            ]),
        ]);
        Dataset::create([
            'name' => 'paypal_payment_rates',
            'value' => json_encode([
                ['name' => 'paypal_fee', 'value' => 3.9],
                ['name' => 'additional_fee', 'value' => 15],
            ]),
        ]);
    }

    private function makePaidTransaction(array $overrides = []): Transaction
    {
        return Transaction::create(array_merge([
            'user_uuid' => $this->customer->uuid,
            'event_uuid' => $this->event->uuid,
            'schedule_uuid' => $this->schedule->uuid,
            'schedule_time_uuid' => $this->scheduleTime->uuid,
            'organization_uuid' => $this->organization->uuid,
            'order_number' => 'ORD-LEDGER-' . uniqid('', true),
            'sub_total' => 1000,
            'tax_amount' => 0,
            'discount' => 0,
            'total_amount' => 1000,
            'status' => Transaction::STATUS['ACTIVE'],
            'payment_status' => Transaction::PAYMENT_STATUS['PAID'],
            'order_status' => Transaction::ORDER_STATUS['CONFIRMED'],
            'paid_at' => now(),
        ], $overrides));
    }

    private function recordCommission(Transaction $tx): void
    {
        (new TransactionCommissionService())
            ->recordPaidTransaction($tx->fresh()->load('event.organization'));
    }

    private function authedGet(string $url)
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $this->token])->getJson($url);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_returns_totals_and_groups_by_provider_and_method(): void
    {
        // 2× PayMongo gcash, 1× PayMongo qrph, 1× PayPal, 1× upgrade — all in range.
        $tx1 = $this->makePaidTransaction([
            'payment_provider' => 'paymongo',
            'total_amount' => 1000,
            'paid_at' => Carbon::parse('2026-05-05 10:00:00'),
            'payment_data' => ['data' => ['attributes' => ['payment_method_used' => 'gcash']]],
        ]);
        $tx2 = $this->makePaidTransaction([
            'payment_provider' => 'paymongo',
            'total_amount' => 500,
            'paid_at' => Carbon::parse('2026-05-06 10:00:00'),
            'payment_data' => ['data' => ['attributes' => ['payment_method_used' => 'gcash']]],
        ]);
        $tx3 = $this->makePaidTransaction([
            'payment_provider' => 'paymongo',
            'total_amount' => 1000,
            'paid_at' => Carbon::parse('2026-05-07 10:00:00'),
            'payment_data' => ['data' => ['attributes' => ['payment_method_used' => 'qrph']]],
        ]);
        $tx4 = $this->makePaidTransaction([
            'payment_provider' => 'paypal',
            'total_amount' => 2000,
            'paid_at' => Carbon::parse('2026-05-08 10:00:00'),
        ]);
        $tx5 = $this->makePaidTransaction([
            'payment_provider' => 'upgrade',
            'total_amount' => 3000,
            'paid_at' => Carbon::parse('2026-05-09 10:00:00'),
        ]);
        // Out-of-range — must NOT be counted.
        $tx6 = $this->makePaidTransaction([
            'payment_provider' => 'paymongo',
            'total_amount' => 9999,
            'paid_at' => Carbon::parse('2026-04-15 10:00:00'),
            'payment_data' => ['data' => ['attributes' => ['payment_method_used' => 'gcash']]],
        ]);

        foreach ([$tx1, $tx2, $tx3, $tx4, $tx5, $tx6] as $tx) {
            $this->recordCommission($tx);
        }

        $response = $this->authedGet('/api/v1/admin/finance/commission-ledger?from=2026-05-01&to=2026-05-31');
        $response->assertSuccessful();

        $data = $response->json('data');
        $this->assertNotNull($data);

        $totals = $data['totals'];
        // Total transactions in range = 5
        $this->assertSame(5, $totals['transaction_count']);
        // Gross = 1000 + 500 + 1000 + 2000 + 3000 = 7500
        $this->assertEquals(7500.00, (float) $totals['gross_amount']);
        // ticketoc_commission @ 10% on each row = 750
        $this->assertEquals(750.00, (float) $totals['ticketoc_commission']);
        // Gateway fees:
        //   gcash:  1000 × 2.23% + 500 × 2.23% = 22.30 + 11.15 = 33.45
        //   qrph:   1000 × 1.34% = 13.40
        //   paypal: 2000 × 3.9%  = 78.00 (commission) + 15 (fixed)
        //   upgrade: 0
        $this->assertEquals(33.45 + 13.40 + 78.00, (float) $totals['payment_gateway_commission']);
        $this->assertEquals(15.00, (float) $totals['payment_gateway_fixed_fee']);
        $this->assertEquals(33.45 + 13.40 + 78.00 + 15.00, (float) $totals['payment_gateway_total']);

        // by_provider — sorted by gross desc: upgrade (3000), paypal (2000), paymongo (2500)
        // Wait: paymongo (1000+500+1000) = 2500 > paypal 2000. Order: upgrade(3000), paymongo(2500), paypal(2000).
        $providers = collect($data['by_provider']);
        $this->assertSame(['upgrade', 'paymongo', 'paypal'], $providers->pluck('payment_provider')->all());

        $paymongo = $providers->firstWhere('payment_provider', 'paymongo');
        $this->assertSame(3, $paymongo['transaction_count']);
        $this->assertEquals(2500.00, (float) $paymongo['gross_amount']);
        $this->assertEquals(33.45 + 13.40, (float) $paymongo['payment_gateway_commission']);

        $paypal = $providers->firstWhere('payment_provider', 'paypal');
        $this->assertSame(1, $paypal['transaction_count']);
        $this->assertEquals(2000.00, (float) $paypal['gross_amount']);
        $this->assertEquals(78.00, (float) $paypal['payment_gateway_commission']);
        $this->assertEquals(15.00, (float) $paypal['payment_gateway_fixed_fee']);

        $upgrade = $providers->firstWhere('payment_provider', 'upgrade');
        $this->assertSame(1, $upgrade['transaction_count']);
        $this->assertEquals(3000.00, (float) $upgrade['gross_amount']);
        $this->assertEquals(0.00, (float) $upgrade['payment_gateway_commission']);

        // cash_by_provider = gross - gateway fees
        $cash = $data['cash_by_provider'];
        $this->assertEquals(round(2500 - 33.45 - 13.40, 2), (float) $cash['paymongo']);
        $this->assertEquals(round(2000 - 78 - 15, 2), (float) $cash['paypal']);
        $this->assertEquals(3000.00, (float) $cash['upgrade']);

        // by_method — verify per-method rows
        $methods = collect($data['by_method'])->keyBy(
            fn ($r) => $r['payment_provider'].':'.($r['payment_method'] ?? '')
        );
        $this->assertEquals(2, $methods['paymongo:gcash']['transaction_count']);
        $this->assertEquals(1500.00, (float) $methods['paymongo:gcash']['gross_amount']);
        $this->assertEquals(1, $methods['paymongo:qrph']['transaction_count']);
        $this->assertEquals(1000.00, (float) $methods['paymongo:qrph']['gross_amount']);
        $this->assertNull($methods['paypal:']['payment_method']);
        $this->assertNull($methods['upgrade:']['payment_method']);

        // Confirm out-of-range tx6 was excluded
        $this->assertSame(6, TransactionCommission::count());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_validates_from_and_to_inputs(): void
    {
        $this->authedGet('/api/v1/admin/finance/commission-ledger')
            ->assertStatus(422)
            ->assertJsonPath('message', 'from and to are required (ISO date or datetime).');

        $this->authedGet('/api/v1/admin/finance/commission-ledger?from=not-a-date&to=2026-05-31')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Invalid from or to.');

        $this->authedGet('/api/v1/admin/finance/commission-ledger?from=2026-06-30&to=2026-05-01')
            ->assertStatus(422)
            ->assertJsonPath('message', 'from must be on or before to.');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_returns_zero_totals_when_no_records_in_range(): void
    {
        $response = $this->authedGet('/api/v1/admin/finance/commission-ledger?from=2026-05-01&to=2026-05-31');
        $response->assertSuccessful();

        $totals = $response->json('data.totals');
        $this->assertSame(0, $totals['transaction_count']);
        $this->assertEquals(0.0, (float) $totals['gross_amount']);
        $this->assertSame([], $response->json('data.by_provider'));
        $this->assertSame([], $response->json('data.by_method'));
        $this->assertEmpty($response->json('data.cash_by_provider'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_rejects_unauthenticated_requests(): void
    {
        $this->getJson('/api/v1/admin/finance/commission-ledger?from=2026-05-01&to=2026-05-31')
            ->assertStatus(401);
    }
}
