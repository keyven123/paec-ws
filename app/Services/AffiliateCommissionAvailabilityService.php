<?php

namespace App\Services;

use Carbon\Carbon;

/**
 * Affiliate commission rows become eligible for payout on fixed calendar dates:
 * — Recorded on days 1–15 of month M → available on day 30 of M (or last day of M if M has fewer than 30 days).
 * — Recorded on days 16–end of M → available on day 15 of month M+1.
 */
class AffiliateCommissionAvailabilityService
{
    public static function timezone(): string
    {
        return (string) config('app.timezone', 'UTC');
    }

    /**
     * First instant the commission row is treated as available for payout (start of that calendar day, app TZ).
     */
    public static function availabilityDate(Carbon $recordedAt): Carbon
    {
        $tz = self::timezone();
        $d = $recordedAt->copy()->timezone($tz);
        $day = $d->day;
        $year = $d->year;
        $month = $d->month;

        if ($day >= 1 && $day <= 15) {
            $first = Carbon::create($year, $month, 1, 0, 0, 0, $tz);
            $daysInMonth = $first->daysInMonth;
            $targetDay = min(30, $daysInMonth);

            return Carbon::create($year, $month, $targetDay, 0, 0, 0, $tz)->startOfDay();
        }

        return $d->copy()->startOfMonth()->addMonth()->day(15)->startOfDay();
    }

    public static function isMaturedAsOf(\DateTimeInterface $recordedAt, Carbon $asOfStartOfDay): bool
    {
        $asOf = $asOfStartOfDay->copy()->timezone(self::timezone())->startOfDay();
        $avail = self::availabilityDate(Carbon::parse($recordedAt))->startOfDay();

        return ! $avail->gt($asOf);
    }
}
