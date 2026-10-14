<?php

namespace Tests\Feature;

use App\Constants\GeneralConstants;
use App\Models\Event;
use App\Models\EventTicket;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\Transaction;
use App\Models\Upload;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Coverage\GapAdmin\Concerns\CreatesGapAdminUsers;
use Tests\Feature\Coverage\GapAdmin\Concerns\CreatesGapPaidTransactions;
use Tests\TestCase;

class AdminDashboardOverviewTest extends TestCase
{
    use CreatesGapAdminUsers;
    use CreatesGapPaidTransactions;
    use RefreshDatabase;

    private const URL = '/api/v1/admin/dashboard/overview';

    private Organization $organization;

    private User $customer;

    private Event $park;

    private Event $museum;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-03-15 10:00:00');

        $this->organization = Organization::factory()->create();
        $this->customer = $this->createGapCustomer('Maria', 'Clara');

        $image = Upload::create([
            'collection' => 'featured',
            'type' => 'image',
            'path' => 'https://cdn.test/park.jpg',
            'order_number' => 0,
        ]);
        $this->park = $this->makeEvent('Sky Park', [
            'status' => GeneralConstants::EVENT_STATUSES['PUBLISHED'],
            'city' => 'Pasay',
            'featured_image_uuid' => $image->uuid,
        ]);
        $this->museum = $this->makeEvent('Wax Museum', [
            'status' => GeneralConstants::EVENT_STATUSES['DRAFT'],
            'city' => null,
            'address' => '12 Roxas Blvd',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function theYearViewComparesPaidOrdersWithTheSameStretchOfLastYear(): void
    {
        $january = $this->paidOrder($this->park, 1000, '2026-01-10 09:00:00');
        $this->ticketsFor($january, 2);
        $this->ticketsFor($january, 1, ['status' => GeneralConstants::TICKET_STATUSES['TRANSFERRED']]);
        $march = $this->paidOrder($this->park, 500, '2026-03-14 18:00:00');
        $this->ticketsFor($march, 1, ['status' => GeneralConstants::TICKET_STATUSES['USED'], 'used_at' => now()]);
        $february = $this->paidOrder($this->museum, 300, '2026-02-01 12:00:00');
        $this->ticketsFor($february, 1);
        $this->ticketsFor($february, 1, ['status' => GeneralConstants::TICKET_STATUSES['CANCELLED']]);

        $this->paidOrder($this->park, 999, '2026-03-01 08:00:00', [
            'payment_status' => Transaction::PAYMENT_STATUS['PENDING'],
        ]);
        $lastYear = $this->paidOrder($this->park, 900, '2025-02-01 08:00:00', [
            'user_uuid' => $this->createGapCustomer('', '')->uuid,
        ]);
        $this->ticketsFor($lastYear, 1, [], '2025-02-01 08:00:00');
        $this->paidOrder($this->park, 5000, '2025-06-01 08:00:00');

        $data = $this->asAdmin()->getJson(self::URL . '?period=year')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->json('data');

        $this->assertSame(['key' => 'year', 'from' => '2026-01-01', 'to' => '2026-03-15', 'compare_label' => 'vs 2025'], $data['period']);
        $this->assertKpi([1800, 900, 100, [1000, 300, 500]], $data['kpis']['revenue']);
        $this->assertKpi([3, 1, 200, [1, 1, 1]], $data['kpis']['orders']);
        $this->assertKpi([4, 1, 300, [2, 1, 1]], $data['kpis']['tickets_sold']);
        $this->assertKpi([600, 900, -33.3, [1000, 300, 500]], $data['kpis']['average_order_value']);
        $this->assertSame(['total' => 2, 'published' => 1], $data['kpis']['attractions']);

        $this->assertSame(['Jan', 'Feb', 'Mar'], array_column($data['series'], 'label'));
        $this->assertSame(['2026-01-01', '2026-02-01', '2026-03-01'], array_column($data['series'], 'date'));

        $expectedStatuses = array_fill_keys(array_values(GeneralConstants::TICKET_STATUSES), 0);
        $this->assertSame(
            array_merge($expectedStatuses, ['active' => 3, 'used' => 1, 'cancelled' => 1, 'transferred' => 1]),
            $data['ticket_statuses'],
        );

        $this->assertSame([
            [
                'event_uuid' => $this->park->uuid,
                'revenue' => 1500,
                'orders' => 2,
                'tickets' => 3,
                'name' => 'Sky Park',
                'slug' => $this->park->slug,
                'location' => 'Pasay',
                'image_url' => 'https://cdn.test/park.jpg',
            ],
            [
                'event_uuid' => $this->museum->uuid,
                'revenue' => 300,
                'orders' => 1,
                'tickets' => 1,
                'name' => 'Wax Museum',
                'slug' => $this->museum->slug,
                'location' => '12 Roxas Blvd',
                'image_url' => null,
            ],
        ], $data['top_attractions']);

        $bookings = $data['recent_bookings'];
        $this->assertSame([$march->uuid, $february->uuid, $january->uuid], array_slice(array_column($bookings, 'uuid'), 0, 3));
        $this->assertSame('Maria Clara', $bookings[0]['customer_name']);
        $this->assertSame('Sky Park', $bookings[0]['attraction']);
        $this->assertEquals(500, $bookings[0]['total_amount']);
        $this->assertSame(1, $bookings[0]['tickets']);
        $guest = collect($bookings)->firstWhere('uuid', $lastYear->uuid);
        $this->assertSame('Guest', $guest['customer_name']);
    }

    #[Test]
    public function shortPeriodsUseDailyBucketsAgainstThePrecedingWindow(): void
    {
        $this->paidOrder($this->park, 400, '2026-03-10 11:00:00');
        $this->paidOrder($this->park, 600, '2026-03-15 09:00:00');
        $this->paidOrder($this->park, 250, '2026-03-05 15:00:00');

        $week = $this->asAdmin()->getJson(self::URL . '?period=7d')->assertOk()->json('data');

        $this->assertSame(['key' => '7d', 'from' => '2026-03-09', 'to' => '2026-03-15', 'compare_label' => 'vs previous 7 days'], $week['period']);
        $this->assertSame(['Mar 9', 'Mar 10', 'Mar 11', 'Mar 12', 'Mar 13', 'Mar 14', 'Mar 15'], array_column($week['series'], 'label'));
        $this->assertKpi([1000, 250, 300, [0, 400, 0, 0, 0, 0, 600]], $week['kpis']['revenue']);
        $this->assertKpi([500, 250, 100, [0, 400, 0, 0, 0, 0, 600]], $week['kpis']['average_order_value']);

        $month = $this->asAdmin()->getJson(self::URL . '?period=30d')->assertOk()->json('data');

        $this->assertSame('2026-02-14', $month['period']['from']);
        $this->assertSame('vs previous 30 days', $month['period']['compare_label']);
        $this->assertCount(30, $month['series']);
        $this->assertEquals(1250, $month['kpis']['revenue']['value']);
        $this->assertNull($month['kpis']['revenue']['change']);
    }

    #[Test]
    public function anEmptyDashboardDefaultsToTheYearWithNothingToCompare(): void
    {
        $data = $this->asAdmin()->getJson(self::URL)->assertOk()->json('data');

        $this->assertSame('year', $data['period']['key']);
        foreach (['revenue', 'orders', 'tickets_sold', 'average_order_value'] as $kpi) {
            $this->assertEquals(0, $data['kpis'][$kpi]['value']);
            $this->assertNull($data['kpis'][$kpi]['change']);
        }
        $this->assertSame([], $data['top_attractions']);
        $this->assertSame([], $data['upcoming_visits']);
        $this->assertSame([], $data['recent_bookings']);
        $this->assertSame([0], array_unique(array_values($data['ticket_statuses'])));
    }

    #[Test]
    public function upcomingVisitsGroupUnusedPaidTicketsByAttractionAndDay(): void
    {
        $first = $this->paidOrder($this->park, 300, '2026-03-01 08:00:00');
        $this->ticketsFor($first, 2, ['valid_until' => '2026-03-17 23:59:59']);
        $this->ticketsFor($first, 1, [
            'valid_until' => '2026-03-17 23:59:59',
            'status' => GeneralConstants::TICKET_STATUSES['USED'],
            'used_at' => now(),
        ]);
        $second = $this->paidOrder($this->park, 150, '2026-03-02 08:00:00');
        $this->ticketsFor($second, 1, ['valid_until' => '2026-03-17 23:59:59']);
        $this->ticketsFor($second, 1, ['valid_until' => '2026-06-01 23:59:59']);
        $this->ticketsFor($second, 1, ['valid_until' => '2026-03-10 23:59:59']);
        $museumOrder = $this->paidOrder($this->museum, 150, '2026-03-03 08:00:00');
        $this->ticketsFor($museumOrder, 1, ['valid_until' => '2026-03-16 23:59:59']);
        $unpaid = $this->paidOrder($this->museum, 150, '2026-03-03 09:00:00', [
            'payment_status' => Transaction::PAYMENT_STATUS['PENDING'],
        ]);
        $this->ticketsFor($unpaid, 1, ['valid_until' => '2026-03-16 23:59:59']);

        $visits = $this->asAdmin()->getJson(self::URL)->assertOk()->json('data.upcoming_visits');

        $this->assertSame([
            ['event_uuid' => $this->museum->uuid, 'date' => '2026-03-16', 'tickets' => 1, 'orders' => 1],
            ['event_uuid' => $this->park->uuid, 'date' => '2026-03-17', 'tickets' => 3, 'orders' => 2],
        ], array_map(
            fn (array $visit) => array_intersect_key($visit, array_flip(['event_uuid', 'date', 'tickets', 'orders'])),
            $visits,
        ));
        $this->assertSame('Sky Park', $visits[1]['name']);
        $this->assertSame('https://cdn.test/park.jpg', $visits[1]['image_url']);
    }

    #[Test]
    public function organizersOnlySeeTheirOwnOrganization(): void
    {
        $this->paidOrder($this->park, 700, '2026-03-01 08:00:00');
        $otherOrganization = Organization::factory()->create();
        $otherEvent = $this->makeEvent('Rival Zoo', [
            'organization_uuid' => $otherOrganization->uuid,
            'status' => GeneralConstants::EVENT_STATUSES['PUBLISHED'],
        ]);
        $this->paidOrder($otherEvent, 9000, '2026-03-01 08:00:00');

        $organizer = $this->createGapAdmin(['dashboard-view'], false);
        $organizer->update(['organization_uuid' => $this->organization->uuid]);

        $this->withHeaders($this->gapAdminHeaders($organizer))
            ->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.kpis.revenue.value', 700)
            ->assertJsonPath('data.kpis.attractions.total', 2)
            ->assertJsonCount(1, 'data.recent_bookings')
            ->assertJsonPath('data.top_attractions.0.name', 'Sky Park');
    }

    #[Test]
    public function anUnknownPeriodIsRejected(): void
    {
        $this->asAdmin()->getJson(self::URL . '?period=decade')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('period');
    }

    /**
     * @param  array{0: float|int, 1: float|int, 2: float|int|null, 3: list<float|int>}  $expected
     */
    private function assertKpi(array $expected, array $kpi): void
    {
        [$value, $previous, $change, $trend] = $expected;

        $this->assertEquals($value, $kpi['value']);
        $this->assertEquals($previous, $kpi['previous']);
        $this->assertEquals($change, $kpi['change']);
        $this->assertEquals($trend, $kpi['trend']);
    }

    private function asAdmin(): static
    {
        return $this->withHeaders($this->gapAdminHeaders($this->createGapAdmin(['dashboard-view'])));
    }

    private function makeEvent(string $name, array $attributes): Event
    {
        return Event::withoutEvents(fn () => Event::factory()->create($attributes + [
            'organization_uuid' => $this->organization->uuid,
            'event_name' => $name,
            'slug' => Str::slug($name),
            'featured_image_uuid' => null,
            'portrait_image_uuid' => null,
        ]));
    }

    private function paidOrder(Event $event, float $amount, string $createdAt, array $overrides = []): Transaction
    {
        $transaction = $this->createGapPaidTransaction($event, $this->customer, $overrides + [
            'total_amount' => $amount,
            'sub_total' => $amount,
        ]);
        Transaction::where('uuid', $transaction->uuid)->update(['created_at' => $createdAt]);

        return $transaction->refresh();
    }

    private function ticketsFor(Transaction $transaction, int $count, array $overrides = [], ?string $createdAt = null): void
    {
        $eventTicket = EventTicket::factory()->create(['event_uuid' => $transaction->event_uuid]);

        for ($i = 0; $i < $count; $i++) {
            $ticket = Ticket::create($overrides + [
                'user_uuid' => $transaction->user_uuid,
                'transaction_uuid' => $transaction->uuid,
                'organization_uuid' => $transaction->organization_uuid,
                'event_uuid' => $transaction->event_uuid,
                'event_ticket_uuid' => $eventTicket->uuid,
                'ticket_number' => 'TKT-' . Str::upper(Str::random(8)),
                'status' => GeneralConstants::TICKET_STATUSES['ACTIVE'],
                'qr_code' => 'QR_' . Str::random(8),
                'attendee_name' => 'Maria Clara',
                'attendee_email' => 'maria@test.com',
                'valid_until' => '2026-12-31 23:59:59',
                'price' => 100,
                'discount' => 0,
            ]);
            if ($createdAt !== null) {
                Ticket::where('uuid', $ticket->uuid)->update(['created_at' => $createdAt]);
            }
        }
    }
}
