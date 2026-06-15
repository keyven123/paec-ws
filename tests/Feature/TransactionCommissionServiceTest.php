<?php

namespace Tests\Feature;

use App\Constants\GeneralConstants;
use App\Http\Repositories\TicketRepository;
use App\Models\AdminUser;
use App\Models\Dataset;
use App\Models\Event;
use App\Models\EventTicket;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\Schedule;
use App\Models\ScheduleTime;
use App\Models\Ticket;
use App\Models\Transaction;
use App\Models\TransactionCommission;
use App\Models\TransactionOrder;
use App\Models\User;
use App\Services\Platform\TransactionCommissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifies the denormalized commission ledger written by
 * {@see TransactionCommissionService} for the paid Paymongo, paid PayPal,
 * upgrade, free and skipped paths — plus an end-to-end check that a ticket
 * upgrade triggered through {@see TicketRepository::upgrade()} writes a row
 * with the *incremental* gross amount (not the full destination price).
 */
class TransactionCommissionServiceTest extends TestCase
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
            'name' => 'Org Test',
            'representative_first_name' => 'Rep',
            'representative_last_name' => 'Resentative',
            'email' => 'org@test.com',
            'status' => GeneralConstants::ORGANIZER_STATUSES['APPROVED'] ?? 'approved',
        ]);

        $this->customer = User::factory()->create([
            'role_uuid' => $this->customerRole->uuid,
        ]);

        $this->event = Event::create([
            'event_name' => 'Commission Event',
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

        // Platform commission % via dataset fallback when organization has no
        // commission_percentage override (null on the test organization below).
        Dataset::create([
            'name' => 'merchant_commission_percentage',
            'value' => '10',
        ]);

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
            'order_number' => 'ORD-COM-' . uniqid('', true),
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
    public function it_records_a_paymongo_gcash_transaction_with_full_breakdown(): void
    {
        $tx = $this->makeTransaction([
            'payment_provider' => 'paymongo',
            'payment_id' => 'pi_paymongo_123',
            'total_amount' => 1000,
            'payment_data' => [
                'data' => [
                    'attributes' => [
                        'payments' => [
                            ['attributes' => ['source' => ['type' => 'gcash']]],
                        ],
                    ],
                ],
            ],
        ]);

        $record = (new TransactionCommissionService())
            ->recordPaidTransaction($tx->fresh()->load('event.organization'));

        $this->assertNotNull($record);
        $this->assertSame(Transaction::class, $record->accountable_type);
        $this->assertSame($tx->uuid, $record->accountable_id);
        $this->assertEquals(1000.00, (float) $record->gross_amount);
        $this->assertEquals(10.00, (float) $record->ticketoc_commission_percent);
        $this->assertEquals(100.00, (float) $record->ticketoc_commission);
        $this->assertSame('paymongo', $record->payment_provider);
        $this->assertSame('gcash', $record->payment_method);
        $this->assertEquals(2.23, (float) $record->payment_gateway_commission_percent);
        // gcash 1000 × 2.23% = 22.30; PayMongo carries no fixed fee.
        $this->assertEquals(22.30, (float) $record->payment_gateway_commission);
        $this->assertEquals(0.00, (float) $record->payment_gateway_fixed_fee);
        $this->assertEquals(0.00, (float) $record->agent_commission);
        // net_amount = gross − ticketoc_commission (organizer's payable).
        $this->assertEquals(900.00, (float) $record->net_amount);
        // ticketoc_net = ticketoc − agent − fixed − commission = 100 − 0 − 0 − 22.30
        $this->assertEquals(77.70, (float) $record->ticketoc_net_commission);
        $this->assertSame(TransactionCommission::TYPE['TRANSACTION'], $record->transaction_type);
        $this->assertSame('pi_paymongo_123', $record->payment_id);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_resolves_paymongo_method_via_payment_method_used_field(): void
    {
        // Newer PayMongo payloads expose the method via
        // data.attributes.payment_method_used. The seeder keys QR PH as
        // 'qrph' (no underscore), so we must look it up under that key.
        $tx = $this->makeTransaction([
            'payment_provider' => 'paymongo',
            'total_amount' => 1000,
            'payment_data' => [
                'data' => [
                    'attributes' => [
                        'payment_method_used' => 'qrph',
                        'payment_method_types' => ['qrph', 'gcash', 'card'],
                    ],
                ],
            ],
        ]);

        $record = (new TransactionCommissionService())
            ->recordPaidTransaction($tx->fresh()->load('event.organization'));

        $this->assertNotNull($record);
        $this->assertSame('qrph', $record->payment_method);
        $this->assertEquals(1.34, (float) $record->payment_gateway_commission_percent);
        $this->assertEquals(13.40, (float) $record->payment_gateway_commission); // 1000 × 1.34%
        $this->assertEquals(0.00, (float) $record->payment_gateway_fixed_fee);
        // ticketoc_net = 100 − 0 − 0 − 13.40 = 86.60
        $this->assertEquals(86.60, (float) $record->ticketoc_net_commission);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_normalizes_paymongo_subtype_methods_to_their_dataset_key(): void
    {
        // brankas_bdo, brankas_landbank, brankas_metrobank all share one rate
        // under the 'brankas' dataset key. Same for dob / dob_ubp / direct_debit.
        $tx = $this->makeTransaction([
            'payment_provider' => 'paymongo',
            'total_amount' => 500,
            'payment_data' => [
                'data' => [
                    'attributes' => [
                        'payment_method_used' => 'brankas_bdo',
                    ],
                ],
            ],
        ]);

        $record = (new TransactionCommissionService())
            ->recordPaidTransaction($tx->fresh()->load('event.organization'));

        $this->assertNotNull($record);
        $this->assertSame('brankas', $record->payment_method);
        $this->assertEquals(1.34, (float) $record->payment_gateway_commission_percent);
        $this->assertEquals(6.70, (float) $record->payment_gateway_commission); // 500 × 1.34%
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_records_a_paypal_transaction_with_percent_and_fixed_fee_snapshot(): void
    {
        $tx = $this->makeTransaction([
            'payment_provider' => 'paypal',
            'payment_id' => 'pp_order_xyz',
            'total_amount' => 2000,
        ]);

        $record = (new TransactionCommissionService())
            ->recordPaidTransaction($tx->fresh()->load('event.organization'));

        $this->assertNotNull($record);
        $this->assertSame('paypal', $record->payment_provider);
        $this->assertNull($record->payment_method);
        $this->assertEquals(3.900, (float) $record->payment_gateway_commission_percent);
        // commission and fixed are disjoint: 2000 × 3.9% = 78; additional_fee = 15
        $this->assertEquals(78.00, (float) $record->payment_gateway_commission);
        $this->assertEquals(15.00, (float) $record->payment_gateway_fixed_fee);
        $this->assertEquals(200.00, (float) $record->ticketoc_commission);
        // net_amount = gross − ticketoc_commission
        $this->assertEquals(1800.00, (float) $record->net_amount);
        // ticketoc_net = 200 − 0 − 15 − 78 = 107
        $this->assertEquals(107.00, (float) $record->ticketoc_net_commission);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_records_an_upgrade_transaction_with_only_ticketoc_commission(): void
    {
        // Mirrors the upgrade scenario from AdminUserStatsPage: a 5k → 8k
        // upgrade only inserts the 3k incremental as the new transaction's
        // total_amount. No gateway is involved (admin-issued), so gateway
        // commission must be 0; affiliate is null on upgrades.
        $tx = $this->makeTransaction([
            'payment_provider' => 'upgrade',
            'total_amount' => 3000,
            'sub_total' => 8000,
            'discount' => 5000,
            'affiliate_partner_uuid' => null,
        ]);

        $record = (new TransactionCommissionService())
            ->recordPaidTransaction($tx->fresh()->load('event.organization'));

        $this->assertNotNull($record);
        $this->assertEquals(3000.00, (float) $record->gross_amount);
        $this->assertSame('upgrade', $record->payment_provider);
        $this->assertNull($record->payment_method);
        $this->assertEquals(0.00, (float) $record->payment_gateway_commission);
        $this->assertEquals(0.00, (float) $record->payment_gateway_fixed_fee);
        $this->assertEquals(0.00, (float) $record->agent_commission);
        $this->assertEquals(10.00, (float) $record->ticketoc_commission_percent);
        $this->assertEquals(300.00, (float) $record->ticketoc_commission);
        // net_amount = gross − ticketoc = 3000 − 300 = 2700 (organizer's payable)
        $this->assertEquals(2700.00, (float) $record->net_amount);
        // ticketoc_net = 300 − 0 − 0 − 0 = 300 (no gateway, no agent on upgrades)
        $this->assertEquals(300.00, (float) $record->ticketoc_net_commission);
        $this->assertSame(TransactionCommission::TYPE['TRANSACTION'], $record->transaction_type);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_skips_free_transactions(): void
    {
        $tx = $this->makeTransaction([
            'payment_provider' => 'free',
            'total_amount' => 0,
        ]);

        $record = (new TransactionCommissionService())
            ->recordPaidTransaction($tx->fresh()->load('event.organization'));

        $this->assertNull($record);
        $this->assertEquals(0, TransactionCommission::count());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_skips_unpaid_transactions(): void
    {
        $tx = $this->makeTransaction([
            'payment_provider' => 'paymongo',
            'payment_status' => Transaction::PAYMENT_STATUS['PENDING'],
        ]);

        $this->assertNull(
            (new TransactionCommissionService())
                ->recordPaidTransaction($tx->fresh()->load('event.organization'))
        );
        $this->assertEquals(0, TransactionCommission::count());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_skips_zero_amount_transactions(): void
    {
        $tx = $this->makeTransaction([
            'payment_provider' => 'paymongo',
            'total_amount' => 0,
            'sub_total' => 0,
        ]);

        $this->assertNull(
            (new TransactionCommissionService())
                ->recordPaidTransaction($tx->fresh()->load('event.organization'))
        );
        $this->assertEquals(0, TransactionCommission::count());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_records_agent_commission_when_affiliate_partner_is_set(): void
    {
        $partner = User::factory()->create([
            'role_uuid' => $this->customerRole->uuid,
        ]);
        $this->event->update([
            'affiliate_enabled' => true,
            'affiliate_commission_percent' => 5,
        ]);

        $tx = $this->makeTransaction([
            'payment_provider' => 'paymongo',
            'total_amount' => 1000,
            'affiliate_partner_uuid' => $partner->uuid,
            'payment_data' => [
                'data' => [
                    'attributes' => [
                        'payments' => [
                            ['attributes' => ['source' => ['type' => 'card']]],
                        ],
                    ],
                ],
            ],
        ]);

        $record = (new TransactionCommissionService())
            ->recordPaidTransaction($tx->fresh()->load('event.organization'));

        $this->assertNotNull($record);
        $this->assertSame($partner->uuid, $record->agent_uuid);
        $this->assertEquals(5.00, (float) $record->agent_commission_percent);
        $this->assertEquals(50.00, (float) $record->agent_commission);
        // card fee 1000 × 2.9% = 29; PayMongo carries no fixed fee.
        $this->assertEquals(29.00, (float) $record->payment_gateway_commission);
        $this->assertEquals(0.00, (float) $record->payment_gateway_fixed_fee);
        // net_amount = gross − ticketoc = 1000 − 100 = 900
        $this->assertEquals(900.00, (float) $record->net_amount);
        // ticketoc_net = 100 − 50 − 0 − 29 = 21
        $this->assertEquals(21.00, (float) $record->ticketoc_net_commission);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_is_idempotent_for_the_same_transaction(): void
    {
        $tx = $this->makeTransaction([
            'payment_provider' => 'paypal',
            'total_amount' => 500,
        ]);

        $service = new TransactionCommissionService();
        $service->recordPaidTransaction($tx->fresh()->load('event.organization'));
        $service->recordPaidTransaction($tx->fresh()->load('event.organization'));

        $this->assertEquals(1, TransactionCommission::count());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_falls_back_to_dataset_merchant_commission_when_organization_has_no_override(): void
    {
        // Make the dataset value distinct from the test default so the source
        // is unambiguous.
        Dataset::where('name', 'merchant_commission_percentage')->update(['value' => '12.5']);

        $tx = $this->makeTransaction([
            'payment_provider' => 'paypal',
            'total_amount' => 800,
        ]);

        $record = (new TransactionCommissionService())
            ->recordPaidTransaction($tx->fresh()->load('event.organization'));

        $this->assertNotNull($record);
        $this->assertEquals(12.50, (float) $record->ticketoc_commission_percent);
        $this->assertEquals(100.00, (float) $record->ticketoc_commission);
        $this->assertEquals(
            'datasets.merchant_commission_percentage',
            data_get($record->metadata, 'organization_commission_source')
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function ticket_upgrade_writes_a_commission_row_with_the_incremental_amount(): void
    {
        // Set up an admin user so we can satisfy `request()->user()->uuid`
        // calls inside TicketRepository::upgrade().
        $adminRole = Role::create([
            'name' => 'Admin',
            'code' => GeneralConstants::ROLES['ADMIN']['name'],
            'is_admin' => true,
        ]);
        $permission = Permission::create([
            'name' => 'Tickets',
            'code' => 'tickets',
            'available_access' => ['view', 'create', 'update', 'delete'],
        ]);
        foreach (['view', 'create', 'update', 'delete'] as $access) {
            RolePermission::create([
                'role_uuid' => $adminRole->uuid,
                'permission_uuid' => $permission->uuid,
                'access' => 'tickets-' . $access,
            ]);
        }
        $admin = AdminUser::create([
            'role_uuid' => $adminRole->uuid,
            'email' => 'admin-com@test.com',
            'password' => 'password123',
            'first_name' => 'Admin',
            'last_name' => 'User',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);
        $token = auth('admin')->login($admin) ?? '';

        $gaType = EventTicket::create([
            'event_uuid' => $this->event->uuid,
            'schedule_uuid' => $this->schedule->uuid,
            'schedule_time_uuid' => $this->scheduleTime->uuid,
            'code' => 'GA',
            'name' => 'GA',
            'price' => 5000,
            'is_bundle' => false,
            'is_unlimited' => true,
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'sold_ticket' => 1,
        ]);
        $vipType = EventTicket::create([
            'event_uuid' => $this->event->uuid,
            'schedule_uuid' => $this->schedule->uuid,
            'schedule_time_uuid' => $this->scheduleTime->uuid,
            'code' => 'VIP',
            'name' => 'VIP',
            'price' => 8000,
            'is_bundle' => false,
            'is_unlimited' => true,
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'sold_ticket' => 0,
        ]);
        $this->event->update(['ticket_sold' => 1]);

        $originalTx = $this->makeTransaction([
            'payment_provider' => 'paymongo',
            'total_amount' => 5000,
            'sub_total' => 5000,
            'organization_uuid' => $this->organization->uuid,
        ]);
        TransactionOrder::create([
            'user_uuid' => $this->customer->uuid,
            'transaction_uuid' => $originalTx->uuid,
            'event_ticket_uuid' => $gaType->uuid,
            'quantity' => 1,
            'price' => 5000,
            'total_amount' => 5000,
            'discount' => 0,
        ]);
        $ticket = Ticket::create([
            'user_uuid' => $this->customer->uuid,
            'organization_uuid' => $this->organization->uuid,
            'transaction_uuid' => $originalTx->uuid,
            'event_uuid' => $this->event->uuid,
            'event_ticket_uuid' => $gaType->uuid,
            'attendee_name' => 'Patron',
            'attendee_email' => 'patron@example.com',
            'qr_code' => 'QR-COM-' . uniqid('', true),
            'ticket_number' => 'TN-COM-' . uniqid('', true),
            'status' => GeneralConstants::TICKET_STATUSES['ACTIVE'],
        ]);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->putJson("/api/v1/tickets/{$ticket->uuid}/upgrade", [
                'ticket_uuid' => $vipType->uuid,
                'amount' => 8000,
            ])
            ->assertStatus(200);

        $upgradeTx = Transaction::query()->where('payment_provider', 'upgrade')->first();
        $this->assertNotNull($upgradeTx);
        $this->assertEquals(3000.0, (float) $upgradeTx->total_amount);

        $record = TransactionCommission::query()
            ->where('accountable_type', Transaction::class)
            ->where('accountable_id', $upgradeTx->uuid)
            ->first();

        $this->assertNotNull($record, 'Expected a transaction_commissions row for the upgrade.');
        $this->assertEquals(3000.00, (float) $record->gross_amount);
        $this->assertEquals(300.00, (float) $record->ticketoc_commission);
        $this->assertEquals(0.00, (float) $record->payment_gateway_commission);
        $this->assertEquals(0.00, (float) $record->payment_gateway_fixed_fee);
        $this->assertEquals(0.00, (float) $record->agent_commission);
        // net_amount (organizer's payable) = 3000 − 300 = 2700
        $this->assertEquals(2700.00, (float) $record->net_amount);
        // ticketoc_net (what TicketOC keeps) = 300 − 0 − 0 − 0 = 300
        $this->assertEquals(300.00, (float) $record->ticketoc_net_commission);
        $this->assertSame('upgrade', $record->payment_provider);
        $this->assertSame(TransactionCommission::TYPE['TRANSACTION'], $record->transaction_type);
        // No commission row should exist for the *original* transaction —
        // only the upgrade flow records here. The original would only be
        // recorded by completePayment(), which we didn't go through.
        $this->assertNull(
            TransactionCommission::query()
                ->where('accountable_id', $originalTx->uuid)
                ->first()
        );
    }
}
