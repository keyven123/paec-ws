<?php

namespace Tests\Feature\Coverage\PlatformServices;

use App\Models\Event;
use App\Models\EventSection;
use App\Models\Transaction;
use App\Services\Platform\AdminTransactionPnLService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminTransactionPnLServiceCoverageTest extends TestCase
{
    use PlatformFixtures;
    use RefreshDatabase;

    private AdminTransactionPnLService $service;

    private Event $concert;

    private Event $pass;

    private Event $untitled;

    private Event $funpark;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerMysqlDateFormat();
        Carbon::setTestNow($this->at('2026-03-25 12:00:00'));
        $this->seedRates(10);
        $this->service = new AdminTransactionPnLService();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function paymongo(string $type): array
    {
        return ['data' => ['attributes' => ['payments' => [['attributes' => ['source' => ['type' => $type]]]]]]];
    }

    private function seedMarch(): void
    {
        $featured = $this->makeSection(EventSection::FEATURED_SECTION);
        $openPass = $this->makeSection(EventSection::OPEN_PASS_SECTION);
        $fun = $this->makeSection(EventSection::AMUSEMENT_SECTION);
        $life = $this->makeSection(EventSection::LIFE_SECTION);

        $orgA = $this->makeOrg('Org A', 15);
        $orgB = $this->makeOrg('Org B');

        $this->concert = $this->makeEvent($orgA, $featured, [
            'event_name' => 'Concert',
            'published_at' => $this->at('2026-02-01 08:00:00'),
        ]);
        $this->pass = $this->makeEvent($orgB, $openPass, [
            'event_name' => 'Pass',
            'published_at' => $this->at('2026-03-28 08:00:00'),
        ]);
        $this->untitled = $this->makeEvent(null, $featured, ['event_name' => '']);
        DB::table('events')->where('uuid', $this->untitled->uuid)->update(['published_at' => null, 'created_at' => null]);
        $this->funpark = $this->makeEvent($orgA, $fun, ['event_name' => 'Funpark']);
        $lifeEvent = $this->makeEvent($orgA, $life, ['event_name' => 'Life']);
        $deleted = $this->makeEvent($orgA, $featured, ['event_name' => 'Deleted']);
        $deleted->delete();

        // Concert: Org A at 15%
        $t1 = $this->makeTx([
            'event_uuid' => $this->concert->uuid,
            'organization_uuid' => $orgA->uuid,
            'payment_provider' => 'paypal',
            'total_amount' => 1000,
            'paid_at' => $this->at('2026-03-05 10:00:00'),
        ]);
        $this->addOrder($t1, 2, 600);
        $this->addOrder($t1, 1, 400);
        $this->makeTx([
            'event_uuid' => $this->concert->uuid,
            'organization_uuid' => $orgA->uuid,
            'payment_provider' => 'paymongo',
            'payment_data' => $this->paymongo('card'),
            'total_amount' => 500,
            'paid_at' => $this->at('2026-03-20 10:00:00'),
        ]);

        // Pass: platform default 10% (no org on the tx, unknown org, paid_at fallback to created_at)
        $t3 = $this->makeTx([
            'event_uuid' => $this->pass->uuid,
            'payment_provider' => 'paypal',
            'total_amount' => 2000,
            'paid_at' => $this->at('2026-03-10 10:00:00'),
        ]);
        $this->addOrder($t3, 4, 2000);
        $this->makeTx([
            'event_uuid' => $this->pass->uuid,
            'organization_uuid' => (string) Str::uuid(),
            'payment_provider' => 'paypal',
            'total_amount' => 100,
            'paid_at' => $this->at('2026-03-11 10:00:00'),
        ]);
        $t11 = $this->makeTx([
            'event_uuid' => $this->pass->uuid,
            'payment_provider' => 'paypal',
            'total_amount' => 400,
            'paid_at' => null,
        ]);
        $this->addOrder($t11, 1, 400);
        $this->setTxColumns($t11, ['created_at' => '2026-03-12 10:00:00']);

        // Funpark (fun view only)
        $t4 = $this->makeTx([
            'event_uuid' => $this->funpark->uuid,
            'organization_uuid' => $orgA->uuid,
            'payment_provider' => 'paymongo',
            'payment_data' => $this->paymongo('gcash'),
            'total_amount' => 300,
            'paid_at' => $this->at('2026-03-06 10:00:00'),
        ]);
        $this->addOrder($t4, 1, 300);

        // Ignored: no event, life section, deleted event, February, unpaid.
        $this->makeTx(['event_uuid' => '', 'total_amount' => 5000, 'paid_at' => $this->at('2026-03-07 10:00:00')]);
        $this->makeTx(['event_uuid' => $lifeEvent->uuid, 'total_amount' => 5000, 'paid_at' => $this->at('2026-03-07 10:00:00')]);
        $this->makeTx(['event_uuid' => $deleted->uuid, 'total_amount' => 5000, 'paid_at' => $this->at('2026-03-07 10:00:00')]);
        $this->makeTx(['event_uuid' => $this->concert->uuid, 'total_amount' => 5000, 'paid_at' => $this->at('2026-02-07 10:00:00')]);
        $this->makeTx([
            'event_uuid' => $this->concert->uuid,
            'total_amount' => 5000,
            'payment_status' => Transaction::PAYMENT_STATUS['PENDING'],
            'paid_at' => null,
        ]);

        // Refunds (by updated_at in March)
        $refund = $this->makeTx([
            'event_uuid' => $this->concert->uuid,
            'status' => Transaction::STATUS['REFUNDED'],
            'payment_status' => Transaction::PAYMENT_STATUS['CANCELLED'],
            'total_amount' => 100,
            'paid_at' => null,
        ]);
        $this->setTxColumns($refund, ['updated_at' => '2026-03-15 10:00:00', 'created_at' => '2026-01-01 10:00:00']);
        $orphanRefund = $this->makeTx([
            'event_uuid' => '',
            'status' => Transaction::STATUS['REFUNDED'],
            'payment_status' => Transaction::PAYMENT_STATUS['CANCELLED'],
            'total_amount' => 70,
            'paid_at' => null,
        ]);
        $this->setTxColumns($orphanRefund, ['updated_at' => '2026-03-15 10:00:00']);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    private function byName(array $rows): array
    {
        return array_column($rows, null, 'name');
    }

    #[Test]
    public function eventsViewGroupsPaidTransactionsPerEventSortedByNetRevenue(): void
    {
        $this->seedMarch();

        $result = $this->service->build(AdminTransactionPnLService::VIEW_EVENTS, '2026-03');

        $this->assertSame('events', $result['view']);
        $this->assertSame('2026-03', $result['month']);
        $this->assertSame('revenue', $result['sort']);
        $this->assertSame(['2026-03', '2026-02'], $result['available_months']);
        $this->assertSame(['current_page' => 1, 'last_page' => 1, 'per_page' => 10, 'total' => 3], $result['meta']);
        $this->assertSame(['Pass', 'Concert', 'Untitled event'], array_column($result['rows'], 'name'));

        $rows = $this->byName($result['rows']);
        $this->assertSame([
            'id' => $this->concert->uuid,
            'name' => 'Concert',
            'organizer' => 'Org A',
            'date_label' => 'Mar 20',
            'tickets' => 3,
            'gmv' => 1500.0,
            'refund_pct' => 6.7,
            'take_rate' => 15.0,
            'net_revenue' => 225.0,
            'margin' => 160.0,
        ], $rows['Concert']);

        // 2000×4%+10 + 100×4%+10 + 400×4%+10 = 130 gateway; 10% of 2500 = 250 revenue.
        $this->assertSame([
            'id' => $this->pass->uuid,
            'name' => 'Pass',
            'organizer' => 'Org B',
            'date_label' => 'Mar 28',
            'tickets' => 5,
            'gmv' => 2500.0,
            'refund_pct' => 0.0,
            'take_rate' => 10.0,
            'net_revenue' => 250.0,
            'margin' => 120.0,
        ], $rows['Pass']);

        $this->assertSame([
            'id' => $this->untitled->uuid,
            'name' => 'Untitled event',
            'organizer' => 'Unknown organizer',
            'date_label' => 'â€”',
            'tickets' => 0,
            'gmv' => 0.0,
            'refund_pct' => 0.0,
            'take_rate' => 0.0,
            'net_revenue' => 0.0,
            'margin' => 0.0,
        ], $rows['Untitled event']);
    }

    #[Test]
    public function rowsCanBeSortedByGmvOrMargin(): void
    {
        $this->seedMarch();

        $byGmv = $this->service->build('events', '2026-03', 'gmv');
        $byMargin = $this->service->build('events', '2026-03', 'margin');

        $this->assertSame('gmv', $byGmv['sort']);
        $this->assertSame(['Pass', 'Concert', 'Untitled event'], array_column($byGmv['rows'], 'name'));
        $this->assertSame('margin', $byMargin['sort']);
        $this->assertSame(['Concert', 'Pass', 'Untitled event'], array_column($byMargin['rows'], 'name'));
    }

    #[Test]
    public function unknownSortFallsBackToRevenue(): void
    {
        $this->seedMarch();

        $result = $this->service->build('events', '2026-03', 'bogus');

        $this->assertSame('revenue', $result['sort']);
        $this->assertSame(['Pass', 'Concert', 'Untitled event'], array_column($result['rows'], 'name'));
    }

    #[Test]
    public function funViewOnlyIncludesAmusementEvents(): void
    {
        $this->seedMarch();

        $result = $this->service->build(AdminTransactionPnLService::VIEW_FUN, '2026-03');

        $this->assertCount(1, $result['rows']);
        $row = $result['rows'][0];
        $this->assertSame('Funpark', $row['name']);
        $this->assertSame(300.0, $row['gmv']);
        $this->assertSame(45.0, $row['net_revenue']);
        // 15% of 300 − gcash 2% fee (6)
        $this->assertSame(39.0, $row['margin']);
        $this->assertSame(15.0, $row['take_rate']);
        $this->assertSame(1, $row['tickets']);
    }

    #[Test]
    public function unknownViewHasNoRows(): void
    {
        $this->seedMarch();

        $result = $this->service->build('venues', '2026-03');

        $this->assertSame([], $result['rows']);
        $this->assertSame(['current_page' => 1, 'last_page' => 1, 'per_page' => 10, 'total' => 0], $result['meta']);
    }

    #[Test]
    public function paginationClampsPageAndPerPage(): void
    {
        $this->seedMarch();

        $second = $this->service->build('events', '2026-03', 'revenue', 2, 1);
        $this->assertSame(['Concert'], array_column($second['rows'], 'name'));
        $this->assertSame(['current_page' => 2, 'last_page' => 3, 'per_page' => 1, 'total' => 3], $second['meta']);

        $clamped = $this->service->build('events', '2026-03', 'revenue', 99, 0);
        $this->assertSame(['Untitled event'], array_column($clamped['rows'], 'name'));
        $this->assertSame(3, $clamped['meta']['current_page']);
        $this->assertSame(1, $clamped['meta']['per_page']);

        $big = $this->service->build('events', '2026-03', 'revenue', -5, 500);
        $this->assertSame(1, $big['meta']['current_page']);
        $this->assertSame(100, $big['meta']['per_page']);
    }

    #[Test]
    public function invalidMonthDefaultsToTheCurrentMonthAndEmptyDataListsIt(): void
    {
        $result = $this->service->build('events', 'March 2026');

        $this->assertSame('2026-03', $result['month']);
        $this->assertSame(['2026-03'], $result['available_months']);
        $this->assertSame([], $result['rows']);
    }

    #[Test]
    public function otherMonthsExcludeMarchActivity(): void
    {
        $this->seedMarch();

        $result = $this->service->build('events', '2026-02');

        $rows = $this->byName($result['rows']);
        $this->assertSame(5000.0, $rows['Concert']['gmv']);
        $this->assertSame(0.0, $rows['Pass']['gmv']);
        $this->assertSame(0.0, $rows['Concert']['refund_pct']);
        // Concert's published date (Feb 1) is earlier than the Feb 7 payment.
        $this->assertSame('Feb 7', $rows['Concert']['date_label']);
    }
}
