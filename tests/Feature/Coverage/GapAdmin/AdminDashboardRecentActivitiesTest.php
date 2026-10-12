<?php

namespace Tests\Feature\Coverage\GapAdmin;

use App\Models\Event;
use App\Models\EventTicket;
use App\Models\Organization;
use App\Models\TransactionOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Coverage\GapAdmin\Concerns\CreatesGapAdminUsers;
use Tests\Feature\Coverage\GapAdmin\Concerns\CreatesGapPaidTransactions;
use Tests\TestCase;

class AdminDashboardRecentActivitiesTest extends TestCase
{
    use CreatesGapAdminUsers;
    use CreatesGapPaidTransactions;
    use RefreshDatabase;

    private const URL = '/api/v1/admin/dashboard/recent-activities';

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();

        $organization = Organization::factory()->create();
        $this->event = Event::withoutEvents(fn () => Event::factory()->create([
            'organization_uuid' => $organization->uuid,
            'event_name' => 'Summer Fest',
        ]));
    }

    private function asDashboardAdmin(): static
    {
        return $this->withHeaders($this->gapAdminHeaders($this->createGapAdmin(['dashboard-view'])));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function purchaseActivities(): array
    {
        $activities = $this->asDashboardAdmin()
            ->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->json('activities');

        return array_values(array_filter($activities, fn (array $a) => $a['type'] === 'purchase'));
    }

    #[Test]
    public function itDescribesGatewayPurchasesWithTicketBreakdown(): void
    {
        $customer = $this->createGapCustomer('Maria', 'Clara');
        $transaction = $this->createGapPaidTransaction($this->event, $customer, [
            'payment_order_id' => 'pay_order_123',
        ]);
        $ticket = EventTicket::factory()->create([
            'event_uuid' => $this->event->uuid,
            'name' => 'VIP',
        ]);
        TransactionOrder::query()->create([
            'user_uuid' => $customer->uuid,
            'transaction_uuid' => $transaction->uuid,
            'event_ticket_uuid' => $ticket->uuid,
            'quantity' => 2,
            'price' => 500,
            'total_amount' => 1000,
        ]);

        $purchases = $this->purchaseActivities();

        $this->assertCount(1, $purchases);
        $this->assertSame(
            "{$customer->full_name} purchased tickets for Summer Fest: VIP x2",
            $purchases[0]['message'],
        );
        $this->assertSame($transaction->uuid, $purchases[0]['data']['uuid']);
    }

    #[Test]
    public function itDescribesManualTicketGrantsAsSystemWhenCreatorIsUnknown(): void
    {
        $customer = $this->createGapCustomer('Juan', 'Luna');
        $transaction = $this->createGapPaidTransaction($this->event, $customer, [
            'payment_order_id' => null,
            'created_by' => null,
        ]);

        $purchases = $this->purchaseActivities();

        $this->assertCount(1, $purchases);
        $this->assertSame(
            "System manually added ticket to user {$customer->full_name} for Summer Fest",
            $purchases[0]['message'],
        );
        $this->assertSame($transaction->uuid, $purchases[0]['data']['uuid']);
    }
}
