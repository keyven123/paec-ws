<?php

namespace Tests\Feature\Coverage\AnalyticsRepository;

use PHPUnit\Framework\Attributes\Test;

class SuccessfulFailedCountsSeriesTest extends AnalyticsRepositoryTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedSeriesFixtures();
    }

    #[Test]
    public function dailyCountsSplitPaidFromFailedAndCancelledForTheOrganization(): void
    {
        $data = $this->repository()->getSuccessfulFailedTransactionCountsSeries('daily', $this->organization->uuid, '2026-05-11', '2026-05-19');

        $this->assertSame('daily', $data['granularity']);
        $this->assertSame('2026-05-11', $data['start_date']);
        $this->assertSame('2026-05-19', $data['end_date']);
        $this->assertCount(9, $data['series']);
        $this->assertSame(['date' => '2026-05-11', 'successful_count' => 1, 'failed_count' => 0], $data['series'][0]);
        $this->assertSame(['date' => '2026-05-12', 'successful_count' => 0, 'failed_count' => 1], $data['series'][1]);
        $this->assertSame(['date' => '2026-05-13', 'successful_count' => 0, 'failed_count' => 0], $data['series'][2]);
        $this->assertSame(['date' => '2026-05-19', 'successful_count' => 2, 'failed_count' => 1], $data['series'][8]);
    }

    #[Test]
    public function weeklyCountsBucketByMondayStartedWeeks(): void
    {
        $data = $this->repository()->getSuccessfulFailedTransactionCountsSeries('weekly', null, '2026-05-11', '2026-05-20');

        $this->assertSame([
            ['date' => '2026-05-11', 'successful_count' => 1, 'failed_count' => 1],
            ['date' => '2026-05-18', 'successful_count' => 3, 'failed_count' => 1],
        ], $data['series']);
    }

    #[Test]
    public function monthlyCountsBucketByCalendarMonth(): void
    {
        $data = $this->repository()->getSuccessfulFailedTransactionCountsSeries('monthly', null, '2026-03-01', '2026-05-31');

        $this->assertSame([
            ['date' => '2026-03', 'successful_count' => 1, 'failed_count' => 0],
            ['date' => '2026-04', 'successful_count' => 0, 'failed_count' => 0],
            ['date' => '2026-05', 'successful_count' => 4, 'failed_count' => 2],
        ], $data['series']);
    }

    #[Test]
    public function yearlyCountsBucketByYear(): void
    {
        $data = $this->repository()->getSuccessfulFailedTransactionCountsSeries('yearly', null, '2025-01-01', '2026-12-31');

        $this->assertSame([
            ['date' => '2025', 'successful_count' => 1, 'failed_count' => 0],
            ['date' => '2026', 'successful_count' => 5, 'failed_count' => 2],
        ], $data['series']);
    }

    #[Test]
    public function unknownGranularityFallsBackToDailyCounts(): void
    {
        $data = $this->repository()->getSuccessfulFailedTransactionCountsSeries('quarterly', null, '2026-05-19', '2026-05-19');

        $this->assertSame('quarterly', $data['granularity']);
        $this->assertSame([
            ['date' => '2026-05-19', 'successful_count' => 3, 'failed_count' => 1],
        ], $data['series']);
    }

    #[Test]
    public function overallTotalsAreScopedToTheOrganization(): void
    {
        $repository = $this->repository();

        $this->assertSame(['successful_count' => 6, 'failed_count' => 2], $repository->getSuccessfulFailedTransactionTotals(null));
        $this->assertSame(['successful_count' => 5, 'failed_count' => 2], $repository->getSuccessfulFailedTransactionTotals($this->organization->uuid));
        $this->assertSame(['successful_count' => 1, 'failed_count' => 0], $repository->getSuccessfulFailedTransactionTotals($this->otherOrganization->uuid));
    }
}
