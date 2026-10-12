<?php

namespace Tests\Feature\Coverage\AnalyticsRepository;

use App\Models\Event;
use PHPUnit\Framework\Attributes\Test;

class RevenuePerEventSeriesTest extends AnalyticsRepositoryTestCase
{
    /** @var array{chart: Event, other: Event} */
    private array $events;

    protected function setUp(): void
    {
        parent::setUp();

        $this->events = $this->seedSeriesFixtures();
    }

    #[Test]
    public function dailySeriesStacksEventsSortedByNameAndSkipsDeletedEvents(): void
    {
        $aardvark = $this->createEvent($this->otherOrganization, 'Aardvark Event');
        $this->createTransaction($aardvark, 50, '2026-05-19 13:00:00');

        $deleted = $this->createEvent($this->organization, 'Deleted Event');
        $this->createTransaction($deleted, 5000, '2026-05-19 13:00:00');
        $deleted->delete();

        $data = $this->repository()->getRevenuePerEventSeries('daily', null, '2026-05-18', '2026-05-19');

        $this->assertSame('daily', $data['granularity']);
        $this->assertSame('2026-05-18', $data['start_date']);
        $this->assertSame('2026-05-19', $data['end_date']);
        $this->assertSame([
            ['event_uuid' => $aardvark->uuid, 'event_name' => 'Aardvark Event'],
            ['event_uuid' => $this->events['chart']->uuid, 'event_name' => 'Chart Event'],
            ['event_uuid' => $this->events['other']->uuid, 'event_name' => 'Other Event'],
        ], $data['events']);
        $this->assertSame([
            ['date' => '2026-05-18', 'amounts' => [0.0, 0.0, 0.0]],
            ['date' => '2026-05-19', 'amounts' => [50.0, 350.0, 999.0]],
        ], $data['series']);
    }

    #[Test]
    public function hourlySeriesIsScopedToTheOrganization(): void
    {
        $data = $this->repository()->getRevenuePerEventSeries('hourly', $this->organization->uuid, '2026-05-19', '2026-05-19');

        $this->assertSame('2026-05-19 00:00', $data['start_date']);
        $this->assertSame('2026-05-19 23:00', $data['end_date']);
        $this->assertSame([['event_uuid' => $this->events['chart']->uuid, 'event_name' => 'Chart Event']], $data['events']);
        $this->assertCount(24, $data['series']);

        $amounts = array_filter(
            $this->keyByDate($data['series'], 'amounts'),
            fn (array $amounts) => $amounts !== [0.0],
        );
        $this->assertSame([
            '2026-05-19 10:00' => [100.0],
            '2026-05-19 14:00' => [250.0],
        ], $amounts);
    }

    #[Test]
    public function weeklySeriesBucketsByMondayStartedWeeks(): void
    {
        $data = $this->repository()->getRevenuePerEventSeries('weekly', $this->organization->uuid, '2026-05-11', '2026-05-20');

        $this->assertSame([
            ['date' => '2026-05-11', 'amounts' => [400.0]],
            ['date' => '2026-05-18', 'amounts' => [350.0]],
        ], $data['series']);
    }

    #[Test]
    public function monthlySeriesBucketsByCalendarMonth(): void
    {
        $data = $this->repository()->getRevenuePerEventSeries('monthly', null, '2026-03-01', '2026-05-31');

        $this->assertSame(['Chart Event', 'Other Event'], array_column($data['events'], 'event_name'));
        $this->assertSame([
            ['date' => '2026-03', 'amounts' => [300.0, 0.0]],
            ['date' => '2026-04', 'amounts' => [0.0, 0.0]],
            ['date' => '2026-05', 'amounts' => [750.0, 999.0]],
        ], $data['series']);
    }

    #[Test]
    public function yearlyMerchantRevenueUsesSubTotalNetOfDiscounts(): void
    {
        $data = $this->repository()->getRevenuePerEventSeries('yearly', null, '2025-01-01', '2026-12-31', merchantRevenueOnly: true);

        $this->assertSame([
            ['date' => '2025', 'amounts' => [450.0, 0.0]],
            ['date' => '2026', 'amounts' => [1050.0, 999.0]],
        ], $data['series']);
    }

    #[Test]
    public function unknownGranularityFallsBackToDailyBuckets(): void
    {
        $data = $this->repository()->getRevenuePerEventSeries('quarterly', $this->otherOrganization->uuid, '2026-05-19', '2026-05-19');

        $this->assertSame('quarterly', $data['granularity']);
        $this->assertSame([['event_uuid' => $this->events['other']->uuid, 'event_name' => 'Other Event']], $data['events']);
        $this->assertSame([['date' => '2026-05-19', 'amounts' => [999.0]]], $data['series']);
    }

    #[Test]
    public function rangeWithoutRevenueReturnsNoEventsAndEmptyAmounts(): void
    {
        $data = $this->repository()->getRevenuePerEventSeries('daily', null, '2026-04-01', '2026-04-02');

        $this->assertSame([], $data['events']);
        $this->assertSame([
            ['date' => '2026-04-01', 'amounts' => []],
            ['date' => '2026-04-02', 'amounts' => []],
        ], $data['series']);
    }
}
