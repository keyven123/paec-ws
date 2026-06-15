<?php

namespace Tests\Unit;

use App\Services\AffiliateCommissionAvailabilityService;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AffiliateCommissionAvailabilityServiceTest extends TestCase
{
    private string $tz = 'Asia/Manila';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => $this->tz]);
    }

    #[Test]
    public function firstHalfOfMonthMaturesOnDay30OfSameMonth(): void
    {
        $avail = AffiliateCommissionAvailabilityService::availabilityDate(
            Carbon::parse('2026-01-05 10:00:00', $this->tz)
        );
        $this->assertSame('2026-01-30', $avail->toDateString());
    }

    #[Test]
    public function secondHalfOfMonthMaturesOnFifteenthOfNextMonth(): void
    {
        $avail = AffiliateCommissionAvailabilityService::availabilityDate(
            Carbon::parse('2026-01-20 10:00:00', $this->tz)
        );
        $this->assertSame('2026-02-15', $avail->toDateString());
    }

    #[Test]
    public function firstHalfOfFebruaryUsesLastDayWhenMonthHasFewerThan30Days(): void
    {
        $avail = AffiliateCommissionAvailabilityService::availabilityDate(
            Carbon::parse('2026-02-10 10:00:00', $this->tz)
        );
        $this->assertSame('2026-02-28', $avail->toDateString());
    }

    #[Test]
    public function dayFifteenCountsAsFirstHalf(): void
    {
        $avail = AffiliateCommissionAvailabilityService::availabilityDate(
            Carbon::parse('2026-03-15 23:59:59', $this->tz)
        );
        $this->assertSame('2026-03-30', $avail->toDateString());
    }

    #[Test]
    public function daySixteenCountsAsSecondHalf(): void
    {
        $avail = AffiliateCommissionAvailabilityService::availabilityDate(
            Carbon::parse('2026-03-16 00:00:01', $this->tz)
        );
        $this->assertSame('2026-04-15', $avail->toDateString());
    }
}
