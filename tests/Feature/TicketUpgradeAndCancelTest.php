<?php

namespace Tests\Feature;

use App\Constants\GeneralConstants;
use App\Jobs\RecordAffiliateCommissionReversalForCancelledTicketJob;
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
use App\Models\TransactionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class TicketUpgradeAndCancelTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $adminUser;

    private string $adminToken;

    private User $customer;

    private Event $event;

    private Schedule $schedule;

    private ScheduleTime $scheduleTime;

    private EventTicket $gaTicketType;

    private EventTicket $vipTicketType;

    protected function setUp(): void
    {
        parent::setUp();

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

        $this->adminUser = AdminUser::create([
            'role_uuid' => $adminRole->uuid,
            'email' => 'admin-upgrade-cancel@test.com',
            'password' => 'password123',
            'first_name' => 'Admin',
            'last_name' => 'User',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);

        $this->adminToken = auth('admin')->login($this->adminUser) ?? '';

        $this->customer = User::factory()->create([
            'role_uuid' => $adminRole->uuid,
        ]);

        $this->event = Event::create([
            'event_name' => 'Upgrade Cancel Event',
            'event_description' => 'Test',
            'contact_email' => 'e@test.com',
            'event_type' => Event::EVENT_TYPES['SINGLE'],
            'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
            'event_config' => Event::EVENT_CONFIGS['OPEN_TICKET'],
            'tags' => [],
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'ticket_sold' => 0,
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

        $this->gaTicketType = EventTicket::create([
            'event_uuid' => $this->event->uuid,
            'schedule_uuid' => $this->schedule->uuid,
            'schedule_time_uuid' => $this->scheduleTime->uuid,
            'code' => 'GA-UC',
            'name' => 'General Admission',
            'description' => null,
            'price' => 50.00,
            'is_bundle' => false,
            'is_unlimited' => true,
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'sold_ticket' => 0,
        ]);

        $this->vipTicketType = EventTicket::create([
            'event_uuid' => $this->event->uuid,
            'schedule_uuid' => $this->schedule->uuid,
            'schedule_time_uuid' => $this->scheduleTime->uuid,
            'code' => 'VIP-UC',
            'name' => 'VIP',
            'description' => null,
            'price' => 100.00,
            'is_bundle' => false,
            'is_unlimited' => true,
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'sold_ticket' => 0,
        ]);
    }

    /**
     * @return array{0: Ticket, 1: Transaction}
     */
    private function seedActiveTicketWithOrder(
        EventTicket $ticketType,
        float $linePrice,
        float $lineTotalAmount,
        float $transactionTotal,
        int $soldForType = 1
    ): array {
        $ticketType->update(['sold_ticket' => $soldForType]);
        $this->event->update(['ticket_sold' => $soldForType]);

        $transaction = Transaction::create([
            'user_uuid' => $this->customer->uuid,
            'event_uuid' => $this->event->uuid,
            'schedule_uuid' => $this->schedule->uuid,
            'schedule_time_uuid' => $this->scheduleTime->uuid,
            'order_number' => 'ORD-UC-' . uniqid('', true),
            'total_amount' => $transactionTotal,
            'sub_total' => $transactionTotal,
            'tax_amount' => 0.00,
            'discount' => 0.00,
            'status' => Transaction::STATUS['ACTIVE'],
            'payment_status' => Transaction::PAYMENT_STATUS['PAID'],
            'order_status' => Transaction::ORDER_STATUS['CONFIRMED'],
        ]);

        $lineDiscount = max(0, $linePrice - $lineTotalAmount);

        TransactionOrder::create([
            'user_uuid' => $this->customer->uuid,
            'transaction_uuid' => $transaction->uuid,
            'event_ticket_uuid' => $ticketType->uuid,
            'quantity' => 1,
            'price' => $linePrice,
            'total_amount' => $lineTotalAmount,
            'discount' => $lineDiscount,
        ]);

        $ticket = Ticket::create([
            'user_uuid' => $this->customer->uuid,
            'transaction_uuid' => $transaction->uuid,
            'event_uuid' => $this->event->uuid,
            'event_ticket_uuid' => $ticketType->uuid,
            'attendee_name' => 'Patron',
            'attendee_email' => 'patron@example.com',
            'qr_code' => 'QR-UC-' . uniqid('', true),
            'status' => GeneralConstants::TICKET_STATUSES['ACTIVE'],
            'ticket_number' => 'TN-UC-' . uniqid('', true),
        ]);

        return [$ticket, $transaction];
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function upgrade_route_stores_incremental_total_amount_after_crediting_prior_ticket_payment(): void
    {
        [$ticket] = $this->seedActiveTicketWithOrder($this->gaTicketType, 50.0, 50.0, 50.0, 1);

        // Declared total for upgraded tier; net paid for GA line was 50 → incremental = 62.5 − 50 = 12.5
        $amount = 62.5;
        $expectedIncremental = 12.5;
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->putJson("/api/v1/tickets/{$ticket->uuid}/upgrade", [
            'ticket_uuid' => $this->vipTicketType->uuid,
            'amount' => $amount,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', GeneralConstants::TICKET_STATUSES['ACTIVE'])
            ->assertJsonPath('data.event_ticket_uuid', $this->vipTicketType->uuid);

        $newUuid = $response->json('data.uuid');
        $this->assertNotSame($ticket->uuid, $newUuid);

        $ticket->refresh();
        $this->assertSame(GeneralConstants::TICKET_STATUSES['CANCELLED'], $ticket->status);
        $this->assertStringContainsString('Upgraded to:', (string) $ticket->remarks);

        $this->assertNull(
            Transaction::query()
                ->where('payment_provider', 'refund')
                ->where('order_number', 'like', 'UPGRADE-REFUND-%')
                ->first()
        );

        $upgradeTx = Transaction::query()
            ->where('payment_provider', 'upgrade')
            ->where('order_number', 'like', 'UPGR-%')
            ->first();
        $this->assertNotNull($upgradeTx);
        $this->assertEquals($expectedIncremental, (float) $upgradeTx->total_amount);
        $this->assertEquals(100.0, (float) $upgradeTx->sub_total);
        $this->assertEquals(87.5, (float) $upgradeTx->discount);

        $upgradeOrder = $upgradeTx->transactionOrders()->first();
        $this->assertNotNull($upgradeOrder);
        $this->assertEquals($expectedIncremental, (float) $upgradeOrder->total_amount);
        $this->assertEquals(100.0, (float) $upgradeOrder->price);
        $this->assertEquals(87.5, (float) $upgradeOrder->discount);

        $this->assertDatabaseHas('tickets', [
            'uuid' => $newUuid,
            'type' => Ticket::TYPES['UPGRADE'],
            'event_ticket_uuid' => $this->vipTicketType->uuid,
        ]);

        $this->assertEquals(0, $this->gaTicketType->fresh()->sold_ticket);
        $this->assertEquals(1, $this->vipTicketType->fresh()->sold_ticket);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function upgrade_route_accepts_zero_amount_for_lower_tier(): void
    {
        [$ticket] = $this->seedActiveTicketWithOrder($this->vipTicketType, 100.0, 100.0, 100.0, 1);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->putJson("/api/v1/tickets/{$ticket->uuid}/upgrade", [
            'ticket_uuid' => $this->gaTicketType->uuid,
            'amount' => 0,
        ]);

        $response->assertStatus(200);

        $this->assertNull(
            Transaction::query()
                ->where('payment_provider', 'refund')
                ->where('order_number', 'like', 'UPGRADE-REFUND-%')
                ->first()
        );

        $upgradeTx = Transaction::query()
            ->where('payment_provider', 'upgrade')
            ->orderByDesc('created_at')
            ->first();
        $this->assertNotNull($upgradeTx);
        $this->assertEquals(0.0, (float) $upgradeTx->total_amount);
        $this->assertEquals(50.0, (float) $upgradeTx->sub_total);
        $this->assertEquals(50.0, (float) $upgradeTx->discount);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function upgrade_route_returns_422_when_amount_is_missing(): void
    {
        [$ticket] = $this->seedActiveTicketWithOrder($this->gaTicketType, 50.0, 50.0, 50.0, 1);

        $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->putJson("/api/v1/tickets/{$ticket->uuid}/upgrade", [
            'ticket_uuid' => $this->vipTicketType->uuid,
        ])->assertStatus(422)->assertJsonValidationErrors(['amount']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function upgrade_route_returns_422_when_target_ticket_type_belongs_to_another_event(): void
    {
        [$ticket] = $this->seedActiveTicketWithOrder($this->gaTicketType, 50.0, 50.0, 50.0, 1);

        $otherEvent = Event::create([
            'event_name' => 'Other Event',
            'event_description' => 'Other',
            'contact_email' => 'other@test.com',
            'event_type' => Event::EVENT_TYPES['SINGLE'],
            'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
            'event_config' => Event::EVENT_CONFIGS['OPEN_TICKET'],
            'tags' => [],
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);
        $otherSchedule = Schedule::create([
            'event_uuid' => $otherEvent->uuid,
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-02',
            'status' => 'published',
        ]);
        $otherTime = ScheduleTime::create([
            'schedule_uuid' => $otherSchedule->uuid,
            'time_start' => '09:00:00',
            'time_end' => '11:00:00',
            'status' => 'published',
        ]);
        $foreignType = EventTicket::create([
            'event_uuid' => $otherEvent->uuid,
            'schedule_uuid' => $otherSchedule->uuid,
            'schedule_time_uuid' => $otherTime->uuid,
            'code' => 'FOREIGN',
            'name' => 'Foreign tier',
            'price' => 99.00,
            'is_bundle' => false,
            'is_unlimited' => true,
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->putJson("/api/v1/tickets/{$ticket->uuid}/upgrade", [
            'ticket_uuid' => $foreignType->uuid,
            'amount' => 1,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['ticket_uuid']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function upgrade_route_requires_authentication(): void
    {
        [$ticket] = $this->seedActiveTicketWithOrder($this->gaTicketType, 50.0, 50.0, 50.0, 1);

        $this->putJson("/api/v1/tickets/{$ticket->uuid}/upgrade", [
            'ticket_uuid' => $this->vipTicketType->uuid,
            'amount' => 50,
        ])->assertStatus(401);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function cancel_route_creates_refund_transaction_and_order_matching_repository_formulas(): void
    {
        Bus::fake();

        [$ticket] = $this->seedActiveTicketWithOrder($this->gaTicketType, 100.0, 80.0, 80.0, 1);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->putJson("/api/v1/tickets/{$ticket->uuid}/cancel", [
            'remarks' => 'Customer requested refund',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', GeneralConstants::TICKET_STATUSES['CANCELLED']);

        $refundTx = Transaction::query()
            ->where('payment_provider', 'refund')
            ->where('order_number', 'like', 'CANCEL-%')
            ->first();

        $this->assertNotNull($refundTx);
        $this->assertEquals(-80.0, (float) $refundTx->total_amount);
        $this->assertEquals(100.0, (float) $refundTx->sub_total);
        $this->assertEquals(20.0, (float) $refundTx->discount);

        $refundOrder = $refundTx->transactionOrders()->first();
        $this->assertNotNull($refundOrder);
        $this->assertEquals(100.0, (float) $refundOrder->price);
        $this->assertEquals(80.0, (float) $refundOrder->total_amount);
        $this->assertEquals(20.0, (float) $refundOrder->discount);
        $this->assertNull($refundOrder->seats);

        Bus::assertDispatched(RecordAffiliateCommissionReversalForCancelledTicketJob::class, function ($job) use ($ticket) {
            return $job->ticketUuid === $ticket->uuid;
        });
    }
}
