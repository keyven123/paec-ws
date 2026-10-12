<?php

namespace Tests\Feature\Coverage\RModelsCore;

use App\Models\Organization;
use App\Models\Transaction;
use App\Models\TransactionOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TransactionModelTest extends TestCase
{
    use RefreshDatabase;
    use RModelsCoreFixtures;

    private function order(Transaction $transaction, string $eventTicketUuid, string $validUntil): void
    {
        TransactionOrder::create([
            'user_uuid' => $transaction->user_uuid,
            'transaction_uuid' => $transaction->uuid,
            'event_ticket_uuid' => $eventTicketUuid,
            'quantity' => 1,
            'price' => 100,
            'total_amount' => 100,
            'valid_until' => $validUntil,
        ]);
    }

    #[Test]
    public function filtersBySimpleColumnsAndAmountRange(): void
    {
        $organization = Organization::factory()->create();
        $customer = $this->makeCustomer();
        $occurrence = $this->makeEventOccurrence('2026-06-20');
        $target = $this->makeTransaction($customer, $occurrence, [
            'organization_uuid' => $organization->uuid,
            'transactionable_type' => 'event',
            'transactionable_uuid' => $occurrence['event']->uuid,
            'status' => Transaction::STATUS['REFUNDED'],
            'payment_status' => Transaction::PAYMENT_STATUS['FAILED'],
            'order_status' => Transaction::ORDER_STATUS['PENDING'],
            'total_amount' => 900,
        ]);
        $otherOccurrence = $this->makeEventOccurrence('2026-06-21');
        $cheap = $this->makeTransaction($this->makeCustomer(), $otherOccurrence, [
            'transactionable_type' => 'venue_inquiry',
            'total_amount' => 50,
        ]);

        $cases = [
            ['organization_uuid' => $organization->uuid],
            ['user_uuid' => $customer->uuid],
            ['event_uuid' => $occurrence['event']->uuid],
            ['transactionable_type' => 'event'],
            ['status' => Transaction::STATUS['REFUNDED']],
            ['payment_status' => Transaction::PAYMENT_STATUS['FAILED']],
            ['order_status' => Transaction::ORDER_STATUS['PENDING']],
            ['min_amount' => 500],
        ];

        foreach ($cases as $filters) {
            $this->assertSameModels([$target], Transaction::filters($filters)->get(), json_encode($filters));
        }
        $this->assertSameModels([$cheap], Transaction::filters(['max_amount' => 100])->get());
        $this->assertSameModels([$target], Transaction::ofType('event')->get());
        $this->assertSameModels([$target], Transaction::ownedBy($organization->uuid)->get());
    }

    #[Test]
    public function visitDateRangeFiltersOnTransactionOrderValidity(): void
    {
        $customer = $this->makeCustomer();
        $occurrence = $this->makeEventOccurrence('2026-06-01');
        $june10 = $this->makeTransaction($customer, $occurrence);
        $this->order($june10, $occurrence['eventTicket']->uuid, '2026-06-10 00:00:00');
        $june20 = $this->makeTransaction($customer, $occurrence);
        $this->order($june20, $occurrence['eventTicket']->uuid, '2026-06-20 00:00:00');
        $this->makeTransaction($customer, $occurrence);

        $this->assertSameModels([$june20], Transaction::filters(['visit_start_date' => '2026-06-15'])->get());
        $this->assertSameModels([$june10], Transaction::filters(['visit_end_date' => '2026-06-15'])->get());
        $this->assertSameModels(
            [$june10, $june20],
            Transaction::filters(['visit_start_date' => '2026-06-10', 'visit_end_date' => '2026-06-20'])->get(),
        );
    }

    #[Test]
    public function paymentStatusHelpersReflectPaymentStatus(): void
    {
        $paid = new Transaction(['payment_status' => Transaction::PAYMENT_STATUS['PAID']]);
        $cancelled = new Transaction(['payment_status' => Transaction::PAYMENT_STATUS['CANCELLED']]);
        $pending = new Transaction(['payment_status' => Transaction::PAYMENT_STATUS['PENDING']]);

        $this->assertTrue($paid->isPaid());
        $this->assertFalse($paid->isCancelled());
        $this->assertFalse($paid->isPending());
        $this->assertTrue($cancelled->isCancelled());
        $this->assertFalse($cancelled->isPaid());
        $this->assertTrue($pending->isPending());
        $this->assertFalse($pending->isPaid());
    }

    #[Test]
    public function merchantRevenueSqlUsesGreatestOutsideSqlite(): void
    {
        $this->assertStringStartsWith('CASE WHEN', Transaction::merchantRevenueSqlAmount('t'));

        $default = config('database.default');
        config(['database.default' => 'mysql']);
        try {
            $sql = Transaction::merchantRevenueSqlAmount('t');
        } finally {
            config(['database.default' => $default]);
        }

        $this->assertSame(
            'GREATEST(0, ROUND(COALESCE(t.sub_total, 0) - COALESCE(t.discount, 0) - COALESCE(t.promo_code_discount, 0), 2))',
            $sql,
        );
    }
}
