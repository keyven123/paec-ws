<?php

namespace Tests\Feature\Coverage\AnalyticsRepository;

use PHPUnit\Framework\Attributes\Test;

class TransactionRevenueSeriesTest extends AnalyticsRepositoryTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedSeriesFixtures();
    }

    #[Test]
    public function dailySeriesSumsPaidEventRevenuePerDayAndFillsGaps(): void
    {
        $data = $this->repository()->getTransactionRevenueSeries('daily', null, '2026-05-18', '2026-05-20');

        $this->assertSame('daily', $data['granularity']);
        $this->assertSame('2026-05-18', $data['start_date']);
        $this->assertSame('2026-05-20', $data['end_date']);
        $this->assertSame([
            ['date' => '2026-05-18', 'total_amount' => 0.0],
            ['date' => '2026-05-19', 'total_amount' => 1349.0],
            ['date' => '2026-05-20', 'total_amount' => 0.0],
        ], $data['series']);
    }

    #[Test]
    public function weeklySeriesBucketsByMondayStartedWeeks(): void
    {
        $data = $this->repository()->getTransactionRevenueSeries('weekly', null, '2026-05-05', '2026-05-20');

        $this->assertSame([
            ['date' => '2026-05-04', 'total_amount' => 0.0],
            ['date' => '2026-05-11', 'total_amount' => 400.0],
            ['date' => '2026-05-18', 'total_amount' => 1349.0],
        ], $data['series']);
    }

    #[Test]
    public function weeklySeriesIsScopedToTheGivenOrganization(): void
    {
        $data = $this->repository()->getTransactionRevenueSeries('weekly', $this->organization->uuid, '2026-05-11', '2026-05-20');

        $this->assertSame([
            ['date' => '2026-05-11', 'total_amount' => 400.0],
            ['date' => '2026-05-18', 'total_amount' => 350.0],
        ], $data['series']);
    }

    #[Test]
    public function monthlySeriesBucketsByCalendarMonth(): void
    {
        $data = $this->repository()->getTransactionRevenueSeries('monthly', null, '2026-03-01', '2026-05-31');

        $this->assertSame('2026-03-01', $data['start_date']);
        $this->assertSame('2026-05-31', $data['end_date']);
        $this->assertSame([
            ['date' => '2026-03', 'total_amount' => 300.0],
            ['date' => '2026-04', 'total_amount' => 0.0],
            ['date' => '2026-05', 'total_amount' => 1749.0],
        ], $data['series']);
    }

    #[Test]
    public function yearlySeriesBucketsByYear(): void
    {
        $data = $this->repository()->getTransactionRevenueSeries('yearly', null, '2024-01-01', '2026-12-31');

        $this->assertSame([
            ['date' => '2024', 'total_amount' => 0.0],
            ['date' => '2025', 'total_amount' => 500.0],
            ['date' => '2026', 'total_amount' => 2049.0],
        ], $data['series']);
    }

    #[Test]
    public function yearlyMerchantRevenueUsesSubTotalNetOfDiscounts(): void
    {
        $data = $this->repository()->getTransactionRevenueSeries('yearly', $this->organization->uuid, '2025-01-01', '2026-12-31', merchantRevenueOnly: true);

        $this->assertSame([
            ['date' => '2025', 'total_amount' => 450.0],
            ['date' => '2026', 'total_amount' => 1050.0],
        ], $data['series']);
    }

    #[Test]
    public function unknownGranularityFallsBackToTheLastThirtyDaysDaily(): void
    {
        $data = $this->repository()->getTransactionRevenueSeries('quarterly', null, null, null);

        $this->assertSame('quarterly', $data['granularity']);
        $this->assertSame('2026-04-21', $data['start_date']);
        $this->assertSame('2026-05-20', $data['end_date']);
        $this->assertCount(30, $data['series']);

        $byDate = $this->keyByDate($data['series'], 'total_amount');
        $this->assertSame(400.0, $byDate['2026-05-11']);
        $this->assertSame(1349.0, $byDate['2026-05-19']);
        $this->assertSame(1749.0, array_sum($byDate));
    }

    #[Test]
    public function hourlySeriesUsesDateFormatBucketsOnMySql(): void
    {
        $this->dialectConnection()->reportMySqlDriver = true;

        $data = $this->repository()->getTransactionRevenueSeries('hourly', null, '2026-05-19', '2026-05-19');

        $this->assertSame('2026-05-19 00:00', $data['start_date']);
        $this->assertSame('2026-05-19 23:00', $data['end_date']);
        $this->assertCount(24, $data['series']);

        $byHour = array_filter($this->keyByDate($data['series'], 'total_amount'));
        $this->assertSame([
            '2026-05-19 10:00' => 100.0,
            '2026-05-19 11:00' => 999.0,
            '2026-05-19 14:00' => 250.0,
        ], $byHour);
    }
}
