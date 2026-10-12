<?php

namespace Tests\Feature\Coverage\RModelsCore;

use App\Models\ActivityCompliance;
use App\Models\BlockedDate;
use App\Models\Event;
use App\Models\EventReminderLog;
use App\Models\EventTicketCoupon;
use App\Models\Organization;
use App\Models\TempTransaction;
use App\Models\TempTransactionOrder;
use App\Models\Transaction;
use App\Models\TransactionCommission;
use App\Models\TransactionCompliance;
use App\Models\TransactionOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ModelRelationsTest extends TestCase
{
    use RefreshDatabase;
    use RModelsCoreFixtures;

    private User $customer;

    /** @var array{event: Event, schedule: \App\Models\Schedule, scheduleTime: \App\Models\ScheduleTime, eventTicket: \App\Models\EventTicket} */
    private array $occurrence;

    private Transaction $transaction;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = $this->makeCustomer();
        $this->occurrence = $this->makeEventOccurrence('2026-06-20');
        $this->transaction = $this->makeTransaction($this->customer, $this->occurrence);
    }

    private function tempTransaction(array $attributes = []): TempTransaction
    {
        return TempTransaction::create(array_merge([
            'user_uuid' => $this->customer->uuid,
            'event_uuid' => $this->occurrence['event']->uuid,
            'transactionable_type' => 'event',
            'transactionable_uuid' => $this->occurrence['event']->uuid,
            'sub_total' => 100,
            'total_amount' => 100,
        ], $attributes));
    }

    private function tempOrder(TempTransaction $temp, ?array $seats = null): TempTransactionOrder
    {
        return TempTransactionOrder::create([
            'user_uuid' => $this->customer->uuid,
            'temp_transaction_uuid' => $temp->uuid,
            'event_ticket_uuid' => $this->occurrence['eventTicket']->uuid,
            'quantity' => 1,
            'price' => 100,
            'total_amount' => 100,
            'seats' => $seats,
        ]);
    }

    private function assertBelongsTo(object $relation, string $foreignKey, string $ownerKey = 'uuid'): void
    {
        $this->assertInstanceOf(BelongsTo::class, $relation);
        $this->assertSame($foreignKey, $relation->getForeignKeyName());
        $this->assertSame($ownerKey, $relation->getOwnerKeyName());
    }

    #[Test]
    public function blockedDateBelongsToItsBlockableAndAuditUsers(): void
    {
        $editor = $this->makeCustomer();
        $event = $this->occurrence['event'];
        $blocked = BlockedDate::create([
            'blockable_type' => 'event',
            'blockable_uuid' => $event->uuid,
            'blocked_date' => '2026-12-25',
        ]);
        DB::table('blocked_dates')->where('uuid', $blocked->uuid)
            ->update(['created_by' => $this->customer->uuid, 'updated_by' => $editor->uuid]);
        $blocked = $blocked->fresh();

        $this->assertInstanceOf(MorphTo::class, $blocked->blockable());
        $this->assertTrue($blocked->blockable->is($event));
        $this->assertTrue($blocked->creator->is($this->customer));
        $this->assertTrue($blocked->updater->is($editor));

        $this->assertInstanceOf(MorphMany::class, $event->blockedDates());
        $this->assertSame(['2026-12-25'], $event->blockedDates->map->blocked_date->map->toDateString()->all());
    }

    #[Test]
    public function eventReminderLogBelongsToTransactionUserAndEvent(): void
    {
        $log = EventReminderLog::create([
            'transaction_uuid' => $this->transaction->uuid,
            'user_uuid' => $this->customer->uuid,
            'event_uuid' => $this->occurrence['event']->uuid,
            'reminder_type' => 'day_before',
        ]);

        $this->assertBelongsTo($log->transaction(), 'transaction_uuid');
        $this->assertTrue($log->transaction->is($this->transaction));
        $this->assertTrue($log->user->is($this->customer));
        $this->assertTrue($log->event->is($this->occurrence['event']));
    }

    #[Test]
    public function eventTicketCouponTracksCreatorAndUpdater(): void
    {
        $editor = $this->makeCustomer();
        $coupon = EventTicketCoupon::create([
            'event_ticket_uuid' => $this->occurrence['eventTicket']->uuid,
            'name' => 'Free Drink',
            'created_by' => $this->customer->uuid,
            'updated_by' => $editor->uuid,
        ]);

        $this->assertBelongsTo($coupon->creator(), 'created_by');
        $this->assertTrue($coupon->creator->is($this->customer));
        $this->assertTrue($coupon->updater->is($editor));
    }

    #[Test]
    public function tempTransactionResolvesPolymorphicSubjectAndDeclaredRelations(): void
    {
        $temp = $this->tempTransaction();

        $this->assertInstanceOf(MorphTo::class, $temp->transactionable());
        $this->assertTrue($temp->transactionable->is($this->occurrence['event']));

        $this->assertBelongsTo($temp->eventTicket(), 'event_ticket_uuid');
        $this->assertBelongsTo($temp->creator(), 'created_by');
        $temp->setRawAttributes(array_merge($temp->getAttributes(), [
            'event_ticket_uuid' => $this->occurrence['eventTicket']->uuid,
            'created_by' => $this->customer->uuid,
        ]));
        $this->assertTrue($temp->eventTicket->is($this->occurrence['eventTicket']));
        $this->assertTrue($temp->creator->is($this->customer));
    }

    #[Test]
    public function tempTransactionHasSeatReservationWhenAnyOrderHoldsSeats(): void
    {
        $withoutSeats = $this->tempTransaction();
        $this->tempOrder($withoutSeats, []);
        $this->assertFalse($withoutSeats->fresh()->hasSeatReservation());

        $withSeats = $this->tempTransaction();
        $this->tempOrder($withSeats, []);
        $this->tempOrder($withSeats, ['A-1', 'A-2']);
        $this->assertTrue($withSeats->fresh()->hasSeatReservation());
    }

    #[Test]
    public function tempTransactionOrderBelongsToUserAndHold(): void
    {
        $temp = $this->tempTransaction();
        $order = $this->tempOrder($temp);

        $this->assertBelongsTo($order->tempTransaction(), 'temp_transaction_uuid');
        $this->assertTrue($order->tempTransaction->is($temp));
        $this->assertTrue($order->user->is($this->customer));
    }

    #[Test]
    public function transactionCommissionLinksAccountableTransactionEventOrganizationAndAgent(): void
    {
        $organization = Organization::factory()->create();
        $agent = $this->makeCustomer();
        $commission = TransactionCommission::create([
            'accountable_type' => Transaction::class,
            'accountable_id' => $this->transaction->uuid,
            'transaction_uuid' => $this->transaction->uuid,
            'event_uuid' => $this->occurrence['event']->uuid,
            'organization_uuid' => $organization->uuid,
            'agent_uuid' => $agent->uuid,
            'payment_provider' => TransactionCommission::PROVIDER['PAYMONGO'],
        ]);

        $this->assertInstanceOf(MorphTo::class, $commission->accountable());
        $this->assertTrue($commission->accountable->is($this->transaction));
        $this->assertTrue($commission->transaction->is($this->transaction));
        $this->assertTrue($commission->event->is($this->occurrence['event']));
        $this->assertTrue($commission->organization->is($organization));
        $this->assertBelongsTo($commission->agent(), 'agent_uuid');
        $this->assertTrue($commission->agent->is($agent));
    }

    #[Test]
    public function transactionComplianceBelongsToTransactionAndActivityCompliance(): void
    {
        $compliance = ActivityCompliance::create([
            'activityable_type' => 'event',
            'activityable_id' => $this->occurrence['event']->uuid,
            'label' => 'Environmental fee',
            'percentage' => 5,
        ]);
        $link = TransactionCompliance::create([
            'transaction_uuid' => $this->transaction->uuid,
            'activity_compliance_uuid' => $compliance->uuid,
            'percentage' => 5,
            'amount' => 5,
        ]);

        $this->assertBelongsTo($link->activityCompliance(), 'activity_compliance_uuid');
        $this->assertTrue($link->transaction->is($this->transaction));
        $this->assertTrue($link->activityCompliance->is($compliance));
    }

    #[Test]
    public function transactionOrderBelongsToItsUser(): void
    {
        $order = TransactionOrder::create([
            'user_uuid' => $this->customer->uuid,
            'transaction_uuid' => $this->transaction->uuid,
            'event_ticket_uuid' => $this->occurrence['eventTicket']->uuid,
            'quantity' => 1,
            'price' => 100,
            'total_amount' => 100,
        ]);

        $this->assertBelongsTo($order->user(), 'user_uuid');
        $this->assertTrue($order->user->is($this->customer));
    }
}
