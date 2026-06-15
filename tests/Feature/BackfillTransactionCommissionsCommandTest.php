<?php

namespace Tests\Feature;

use App\Constants\GeneralConstants;
use App\Models\Dataset;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\ScheduleTime;
use App\Models\Transaction;
use App\Models\TransactionCommission;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the artisan command that backfills transaction_commissions rows
 * from existing transactions. Locks in:
 *  - method-key resolution from PayMongo's payment_method_used field
 *    (including the 'qrph' dataset key, which differs from the P&L
 *    bucket convention 'qr_ph').
 *  - PayPal's percent + fixed fee handling (additional_fee always applied).
 *  - Idempotency on re-run.
 *  - --dry-run side-effect freedom.
 *  - --since cutoff filtering.
 *  - Skipping zero-amount, free, and unpaid transactions.
 */
class BackfillTransactionCommissionsCommandTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;
    private Event $event;
    private Organization $organization;
    private Schedule $schedule;
    private ScheduleTime $scheduleTime;
    private Role $customerRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customerRole = Role::create([
            'name' => 'Customer',
            'code' => GeneralConstants::ROLES['CUSTOMER']['name'],
        ]);

        $this->organization = Organization::create([
            'name' => 'Backfill Org',
            'representative_first_name' => 'Rep',
            'email' => 'org-bf@test.com',
            'status' => GeneralConstants::ORGANIZER_STATUSES['APPROVED'] ?? 'approved',
        ]);

        $this->customer = User::factory()->create([
            'role_uuid' => $this->customerRole->uuid,
        ]);

        $this->event = Event::create([
            'event_name' => 'Backfill Event',
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

    private function makeTransaction(array $overrides = []): Transaction
    {
        return Transaction::create(array_merge([
            'user_uuid' => $this->customer->uuid,
            'event_uuid' => $this->event->uuid,
            'schedule_uuid' => $this->schedule->uuid,
            'schedule_time_uuid' => $this->scheduleTime->uuid,
            'organization_uuid' => $this->organization->uuid,
            'order_number' => 'ORD-BF-' . uniqid('', true),
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

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_backfills_paymongo_paypal_and_upgrade_rows_skipping_free_zero_and_unpaid(): void
    {
        // Eligible: PayMongo gcash via payment_method_used.
        $tx1 = $this->makeTransaction([
            'payment_provider' => 'paymongo',
            'total_amount' => 1000,
            'payment_data' => [
                'data' => ['attributes' => ['payment_method_used' => 'gcash']],
            ],
        ]);
        // Eligible: PayPal — percent + fixed.
        $tx2 = $this->makeTransaction([
            'payment_provider' => 'paypal',
            'total_amount' => 2000,
        ]);
        // Eligible: upgrade — only ticketoc commission.
        $tx3 = $this->makeTransaction([
            'payment_provider' => 'upgrade',
            'total_amount' => 3000,
        ]);
        // Skipped: free.
        $this->makeTransaction([
            'payment_provider' => 'free',
            'total_amount' => 0,
        ]);
        // Skipped: zero amount with non-free provider.
        $this->makeTransaction([
            'payment_provider' => 'paymongo',
            'total_amount' => 0,
        ]);
        // Skipped: unpaid.
        $this->makeTransaction([
            'payment_provider' => 'paymongo',
            'total_amount' => 1500,
            'payment_status' => Transaction::PAYMENT_STATUS['PENDING'],
            'paid_at' => null,
        ]);

        $this->artisan('app:backfill-transaction-commissions')->assertSuccessful();

        // 3 written rows: tx1, tx2, tx3.
        $this->assertEquals(3, TransactionCommission::count());

        $r1 = TransactionCommission::where('accountable_id', $tx1->uuid)->first();
        $this->assertNotNull($r1);
        $this->assertSame('gcash', $r1->payment_method);
        $this->assertEquals(22.30, (float) $r1->payment_gateway_commission);
        $this->assertEquals(900.00, (float) $r1->net_amount);
        $this->assertEquals(round(100 - 22.30, 2), (float) $r1->ticketoc_net_commission);

        $r2 = TransactionCommission::where('accountable_id', $tx2->uuid)->first();
        $this->assertNotNull($r2);
        $this->assertNull($r2->payment_method);
        $this->assertEquals(78.00, (float) $r2->payment_gateway_commission); // 2000 × 3.9%
        $this->assertEquals(15.00, (float) $r2->payment_gateway_fixed_fee);
        $this->assertEquals(1800.00, (float) $r2->net_amount);
        $this->assertEquals(107.00, (float) $r2->ticketoc_net_commission);

        $r3 = TransactionCommission::where('accountable_id', $tx3->uuid)->first();
        $this->assertNotNull($r3);
        $this->assertSame('upgrade', $r3->payment_provider);
        $this->assertEquals(0.00, (float) $r3->payment_gateway_commission);
        $this->assertEquals(2700.00, (float) $r3->net_amount);
        $this->assertEquals(300.00, (float) $r3->ticketoc_net_commission);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_resolves_qrph_via_payment_method_used_and_uses_the_qrph_dataset_key(): void
    {
        // QR PH is the regression scenario: the dataset key is 'qrph'
        // (no underscore), so without the dataset-key-aware resolver the
        // rate lookup would fall through and yield 0.
        $tx = $this->makeTransaction([
            'payment_provider' => 'paymongo',
            'total_amount' => 1000,
            'payment_data' => [
                'data' => ['attributes' => ['payment_method_used' => 'qrph']],
            ],
        ]);

        $this->artisan('app:backfill-transaction-commissions')->assertSuccessful();

        $record = TransactionCommission::where('accountable_id', $tx->uuid)->first();
        $this->assertNotNull($record);
        $this->assertSame('qrph', $record->payment_method);
        $this->assertEquals(1.34, (float) $record->payment_gateway_commission_percent);
        $this->assertEquals(13.40, (float) $record->payment_gateway_commission);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_is_idempotent_when_run_twice(): void
    {
        $this->makeTransaction([
            'payment_provider' => 'paymongo',
            'total_amount' => 1000,
            'payment_data' => [
                'data' => ['attributes' => ['payment_method_used' => 'gcash']],
            ],
        ]);

        $this->artisan('app:backfill-transaction-commissions')->assertSuccessful();
        $this->artisan('app:backfill-transaction-commissions')->assertSuccessful();

        $this->assertEquals(1, TransactionCommission::count());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function dry_run_writes_no_rows_but_completes_successfully(): void
    {
        $this->makeTransaction([
            'payment_provider' => 'paymongo',
            'total_amount' => 1000,
            'payment_data' => [
                'data' => ['attributes' => ['payment_method_used' => 'gcash']],
            ],
        ]);
        $this->makeTransaction([
            'payment_provider' => 'paypal',
            'total_amount' => 500,
        ]);

        $this->artisan('app:backfill-transaction-commissions', ['--dry-run' => true])
            ->assertSuccessful();

        $this->assertEquals(0, TransactionCommission::count());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function since_cutoff_only_processes_transactions_on_or_after_the_date(): void
    {
        $oldTx = $this->makeTransaction([
            'payment_provider' => 'paymongo',
            'total_amount' => 1000,
            'paid_at' => Carbon::parse('2026-01-15 10:00:00'),
            'payment_data' => [
                'data' => ['attributes' => ['payment_method_used' => 'gcash']],
            ],
        ]);
        $newTx = $this->makeTransaction([
            'payment_provider' => 'paypal',
            'total_amount' => 2000,
            'paid_at' => Carbon::parse('2026-03-10 10:00:00'),
        ]);

        $this->artisan('app:backfill-transaction-commissions', ['--since' => '2026-03-01'])
            ->assertSuccessful();

        $this->assertEquals(1, TransactionCommission::count());
        $this->assertNull(TransactionCommission::where('accountable_id', $oldTx->uuid)->first());
        $this->assertNotNull(TransactionCommission::where('accountable_id', $newTx->uuid)->first());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_rejects_an_invalid_since_value(): void
    {
        $this->artisan('app:backfill-transaction-commissions', ['--since' => 'not-a-date'])
            ->assertFailed();

        $this->assertEquals(0, TransactionCommission::count());
    }
}
