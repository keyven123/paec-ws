<?php

namespace Tests\Feature\Coverage\AnalyticsRepository;

use App\Constants\GeneralConstants;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;

/**
 * Signups: the base customer (2026-05-20 15:30) plus users created 2026-05-11, 2026-03-10, 2025-08-01 and 2018-02-02.
 * Also exercises the default/swapped date-range resolution shared by every series.
 */
class UserSignupsSeriesTest extends AnalyticsRepositoryTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['2026-05-11 09:00:00', '2026-03-10 08:00:00', '2025-08-01 12:00:00', '2018-02-02 12:00:00'] as $i => $createdAt) {
            $this->createCustomer("signup-{$i}@test.com", Carbon::parse($createdAt, GeneralConstants::TIMEZONE));
        }
    }

    #[Test]
    public function dailySignupsCountUsersCreatedPerDay(): void
    {
        $data = $this->repository()->getUserSignupsSeries('daily', '2026-05-10', '2026-05-12');

        $this->assertSame([
            ['date' => '2026-05-10', 'count' => 0],
            ['date' => '2026-05-11', 'count' => 1],
            ['date' => '2026-05-12', 'count' => 0],
        ], $data['series']);
    }

    #[Test]
    public function weeklySignupsDefaultToTheLastTwelveWeeks(): void
    {
        $data = $this->repository()->getUserSignupsSeries('weekly', null, null);

        $this->assertSame('2026-03-02', $data['start_date']);
        $this->assertSame('2026-05-20', $data['end_date']);
        $this->assertCount(12, $data['series']);
        $this->assertSame('2026-03-02', $data['series'][0]['date']);
        $this->assertSame('2026-05-18', $data['series'][11]['date']);

        $this->assertSame(
            ['2026-03-09' => 1, '2026-05-11' => 1, '2026-05-18' => 1],
            array_filter($this->keyByDate($data['series'], 'count')),
        );
    }

    #[Test]
    public function monthlySignupsDefaultToTheLastTwelveMonths(): void
    {
        $data = $this->repository()->getUserSignupsSeries('monthly', null, null);

        $this->assertSame('2025-06-01', $data['start_date']);
        $this->assertCount(12, $data['series']);
        $this->assertSame('2025-06', $data['series'][0]['date']);
        $this->assertSame('2026-05', $data['series'][11]['date']);

        $this->assertSame(
            ['2025-08' => 1, '2026-03' => 1, '2026-05' => 2],
            array_filter($this->keyByDate($data['series'], 'count')),
        );
    }

    #[Test]
    public function yearlySignupsDefaultToTheLastTenYears(): void
    {
        $data = $this->repository()->getUserSignupsSeries('yearly', null, null);

        $this->assertSame('2017-01-01', $data['start_date']);
        $this->assertCount(10, $data['series']);
        $this->assertSame('2017', $data['series'][0]['date']);
        $this->assertSame('2026', $data['series'][9]['date']);

        $this->assertSame(
            ['2018' => 1, '2025' => 1, '2026' => 3],
            array_filter($this->keyByDate($data['series'], 'count')),
        );
    }

    #[Test]
    public function unknownGranularityFallsBackToDailySignups(): void
    {
        $data = $this->repository()->getUserSignupsSeries('quarterly', '2026-05-11', '2026-05-11');

        $this->assertSame('quarterly', $data['granularity']);
        $this->assertSame([['date' => '2026-05-11', 'count' => 1]], $data['series']);
    }

    #[Test]
    public function reversedDailyRangeIsSwapped(): void
    {
        $data = $this->repository()->getUserSignupsSeries('daily', '2026-05-25', '2026-05-20');

        $this->assertSame('2026-05-20', $data['start_date']);
        $this->assertSame('2026-05-25', $data['end_date']);
        $this->assertCount(6, $data['series']);
        $this->assertSame(['date' => '2026-05-20', 'count' => 1], $data['series'][0]);
    }

    #[Test]
    public function reversedHourlyRangeIsSwappedOnHourBoundaries(): void
    {
        $data = $this->repository()->getUserSignupsSeries('hourly', '2026-05-21', '2026-05-19');

        $this->assertSame('2026-05-19 23:00', $data['start_date']);
        $this->assertSame('2026-05-21 00:00', $data['end_date']);
        $this->assertCount(26, $data['series']);
        $this->assertSame(['2026-05-20 15:00' => 1], array_filter($this->keyByDate($data['series'], 'count')));
    }
}
