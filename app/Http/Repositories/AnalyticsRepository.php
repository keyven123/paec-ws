<?php

namespace App\Http\Repositories;

use App\Constants\GeneralConstants;
use App\Helpers\GeneralHelper;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AnalyticsRepository
{
    private bool $merchantRevenueBasis = false;

    /**
     * @param Transaction $transaction
     */
    public function __construct(protected Transaction $transaction)
    {
    }

    /**
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    private function withMerchantRevenueBasis(bool $merchantRevenueOnly, callable $callback): mixed
    {
        $previous = $this->merchantRevenueBasis;
        $this->merchantRevenueBasis = $merchantRevenueOnly;

        try {
            return $callback();
        } finally {
            $this->merchantRevenueBasis = $previous;
        }
    }

    private function revenueSumSelectSql(?string $tablePrefix = null): string
    {
        if ($this->merchantRevenueBasis) {
            return 'SUM('.Transaction::merchantRevenueSqlAmount($tablePrefix).') as total_amount';
        }

        $column = $tablePrefix ? "{$tablePrefix}.total_amount" : 'total_amount';

        return "SUM({$column}) as total_amount";
    }

    public function exportSalesReport(string $startDate, string $endDate): string
    {
        $transactions = $this->transaction->whereBetween('created_at', [
                Carbon::parse($startDate)->startOfDay(),
                Carbon::parse($endDate)->endOfDay()
            ])
            ->eventsOnly()
            ->with([
                'user',
                'event',
                'transactionOrders.eventTicket' => function ($query) {
                    $query->withTrashed();
                },
            ])
            ->where('payment_status', Transaction::PAYMENT_STATUS['PAID'])
            ->get();

        $data = [];

        // Add CSV headers
        $headers = [
            'Order Number',
            'Purchase Date',
            'Payment Status',
            'Customer Name',
            'Customer Email',
            'Ticket Type',
            'Quantity',
            'Unit Price',
            'Discount',
            'Total Price',
            'Ticket Numbers',
            'Payment Method',
            'Reference Number',
            'Transaction ID',
            'Provider ID',
            'Event Name',
            'Event Schedule',
            'Venue',
            'Promo Code Used',
            'Type'
        ];

        $data[] = $headers;

        foreach ($transactions as $transaction) {
            $transactionOrders = $transaction->transactionOrders;
            $paymentTypeRefNo = GeneralHelper::getPaymentTypeRefNo($transaction->payment_provider, $transaction->payment_data);
            foreach ($transactionOrders as $transactionOrder) {
                if ($transaction->tickets()->where('type', 'complementary')->exists()) {
                    $totalAmount = 0;
                } else {
                    $totalAmount = $transaction->total_amount;
                }

                $tickets = $transaction->tickets()
                    ->where('status', '!=', GeneralConstants::TICKET_STATUSES['TRANSFERRED'])
                    ->where('event_ticket_uuid', $transactionOrder->eventTicket->uuid)
                    ->get();

                $data[] = [
                    $transaction->order_number,
                    Carbon::parse($transaction->paid_at)->format('Y/m/d H:i:s'),
                    $transaction->payment_status,
                    $transaction->user->full_name ?? 'N/A',
                    $transaction->user->email ?? 'N/A',
                    $transactionOrder->eventTicket->name ?? 'N/A',
                    $tickets->count(),
                    number_format($transactionOrder->price, 2),
                    number_format($transactionOrder->discount, 2),
                    number_format($totalAmount, 2),
                    implode('_', $tickets->pluck('ticket_number')->toArray()),
                    $transaction->payment_provider ?? 'N/A',
                    $paymentTypeRefNo,
                    $transaction->payment_order_id ?? 'N/A',
                    $transaction->payment_id ?? 'N/A',
                    $transaction->event?->event_name ?? 'N/A',
                    $transaction->schedule?->date_from ? Carbon::parse($transaction->schedule?->date_from)->format('Y/m/d') . ($transaction->scheduleTime ? ' - ' . Carbon::parse($transaction->scheduleTime?->time_start)->format('H:i') . ' - ' . Carbon::parse($transaction->scheduleTime?->time_end)->format('H:i') : '') : 'N/A',
                    $transaction->event?->venue?->name ?? $transaction->event?->address ?? 'N/A',
                    $transaction->promo_code_uuid ? $transaction->promoCode->code : ($transaction->discount > 0 ? 'EVENT TICKET PROMO' : 'N/A'),
                    $transaction->tickets()->first()->type ?? 'N/A'
                ];
            }
        }

        // Generate CSV content
        $csvContent = '';
        foreach ($data as $row) {
            $csvContent .= '"' . implode('","', $row) . '"' . "\n";
        }

        return $csvContent;
    }

    /**
     * Paid transaction totals over time for charting (x: date bucket, y: sum of total_amount).
     *
     * @return array{granularity: string, start_date: string, end_date: string, series: array<int, array{date: string, total_amount: float}>}
     */
    public function getTransactionRevenueSeries(
        string $granularity,
        ?string $organizationUuid,
        ?string $startDate,
        ?string $endDate,
        bool $merchantRevenueOnly = false,
    ): array {
        return $this->withMerchantRevenueBasis($merchantRevenueOnly, function () use ($granularity, $organizationUuid, $startDate, $endDate) {
            [$start, $end] = $this->resolveSeriesDateBounds($granularity, $startDate, $endDate);

            $base = $this->transaction->newQuery()
                ->eventsOnly()
                ->where('payment_status', Transaction::PAYMENT_STATUS['PAID'])
                ->whereBetween('created_at', [$start, $end]);

            if ($organizationUuid) {
                $base->where('organization_uuid', $organizationUuid);
            }

            $series = match ($granularity) {
                'hourly' => $this->seriesHourly($base, $start, $end),
                'daily' => $this->seriesDaily($base, $start, $end),
                'weekly' => $this->seriesWeekly($base, $start, $end),
                'monthly' => $this->seriesMonthly($base, $start, $end),
                'yearly' => $this->seriesYearly($base, $start, $end),
                default => $this->seriesDaily($base, $start, $end),
            };

            return [
                'granularity' => $granularity,
                'start_date' => $this->formatSeriesRangeBound($start, $granularity),
                'end_date' => $this->formatSeriesRangeBound($end, $granularity),
                'series' => $series,
            ];
        });
    }

    /**
     * CSV export of paid transactions (same line format as event purchasers export).
     * Uses the same date range resolution and organization filter as the revenue chart.
     */
    public function exportTransactionRevenueSeriesCsv(
        string $granularity,
        ?string $organizationUuid,
        ?string $startDate,
        ?string $endDate,
        bool $includeAdminOnlyColumns = true,
    ): string {
        [$start, $end] = $this->resolveSeriesDateBounds($granularity, $startDate, $endDate);

        return app(EventRepository::class)->exportPaidTransactionsInRange(
            $organizationUuid,
            $start,
            $end,
            $includeAdminOnlyColumns,
        );
    }

    /**
     * @param  list<scalar|null>  $row
     */
    private function csvRowToLine(array $row): string
    {
        $escaped = array_map(function ($cell) {
            return str_replace('"', '""', (string) $cell);
        }, $row);

        return '"'.implode('","', $escaped).'"'."\n";
    }

    /**
     * Successful (paid) vs failed non-successful transactions per bucket.
     * Failed counts include payment_status failed and cancelled.
     *
     * @return array{granularity: string, start_date: string, end_date: string, series: array<int, array{date: string, successful_count: int, failed_count: int}>}
     */
    public function getSuccessfulFailedTransactionCountsSeries(
        string $granularity,
        ?string $organizationUuid,
        ?string $startDate,
        ?string $endDate
    ): array {
        [$start, $end] = $this->resolveSeriesDateBounds($granularity, $startDate, $endDate);

        $paid = Transaction::PAYMENT_STATUS['PAID'];
        $failed = Transaction::PAYMENT_STATUS['FAILED'];
        $cancelledPayment = Transaction::PAYMENT_STATUS['CANCELLED'];

        $base = $this->transaction->newQuery()
            ->eventsOnly()
            ->whereIn('payment_status', [$paid, $failed, $cancelledPayment])
            ->whereBetween('created_at', [$start, $end]);

        if ($organizationUuid) {
            $base->where('organization_uuid', $organizationUuid);
        }

        $series = match ($granularity) {
            'hourly' => $this->successfulFailedCountsHourly($base, $paid, $failed, $cancelledPayment, $start, $end),
            'daily' => $this->successfulFailedCountsDaily($base, $paid, $failed, $cancelledPayment, $start, $end),
            'weekly' => $this->successfulFailedCountsWeekly($base, $paid, $failed, $cancelledPayment, $start, $end),
            'monthly' => $this->successfulFailedCountsMonthly($base, $paid, $failed, $cancelledPayment, $start, $end),
            'yearly' => $this->successfulFailedCountsYearly($base, $paid, $failed, $cancelledPayment, $start, $end),
            default => $this->successfulFailedCountsDaily($base, $paid, $failed, $cancelledPayment, $start, $end),
        };

        return [
            'granularity' => $granularity,
            'start_date' => $this->formatSeriesRangeBound($start, $granularity),
            'end_date' => $this->formatSeriesRangeBound($end, $granularity),
            'series' => $series,
        ];
    }

    /**
     * User signups over time for charting (x: date bucket, y: count of users created).
     *
     * @return array{granularity: string, start_date: string, end_date: string, series: array<int, array{date: string, count: int}>}
     */
    public function getUserSignupsSeries(
        string $granularity,
        ?string $startDate,
        ?string $endDate
    ): array {
        [$start, $end] = $this->resolveSeriesDateBounds($granularity, $startDate, $endDate);

        $base = User::query()
            ->whereBetween('created_at', [$start, $end]);

        $series = match ($granularity) {
            'hourly' => $this->countSeriesHourly($base, $start, $end),
            'daily' => $this->countSeriesDaily($base, $start, $end),
            'weekly' => $this->countSeriesWeekly($base, $start, $end),
            'monthly' => $this->countSeriesMonthly($base, $start, $end),
            'yearly' => $this->countSeriesYearly($base, $start, $end),
            default => $this->countSeriesDaily($base, $start, $end),
        };

        return [
            'granularity' => $granularity,
            'start_date' => $this->formatSeriesRangeBound($start, $granularity),
            'end_date' => $this->formatSeriesRangeBound($end, $granularity),
            'series' => $series,
        ];
    }

    private function formatSeriesRangeBound(Carbon $bound, string $granularity): string
    {
        return $granularity === 'hourly'
            ? $bound->copy()->startOfHour()->format('Y-m-d H:i')
            : $bound->toDateString();
    }

    private function hourlyBucketExpression(string $column = 'created_at'): string
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return "strftime('%Y-%m-%d %H:00', {$column})";
        }

        return "DATE_FORMAT({$column}, '%Y-%m-%d %H:00')";
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolveSeriesDateBounds(string $granularity, ?string $startDate, ?string $endDate): array
    {
        $isHourly = $granularity === 'hourly';

        if ($endDate !== null && $endDate !== '') {
            $end = Carbon::parse($endDate);
            $end = $isHourly ? $end->copy()->endOfDay() : $end->copy()->endOfDay();
        } else {
            $end = $isHourly ? Carbon::now()->endOfHour() : Carbon::now()->endOfDay();
        }

        if ($startDate !== null && $startDate !== '') {
            $start = Carbon::parse($startDate);
            $start = $isHourly ? $start->copy()->startOfDay() : $start->copy()->startOfDay();
        } else {
            $start = match ($granularity) {
                'hourly' => Carbon::now()->copy()->subHours(23)->startOfHour(),
                'daily' => Carbon::now()->copy()->subDays(29)->startOfDay(),
                'weekly' => Carbon::now()->copy()->subWeeks(11)->startOfWeek(Carbon::MONDAY)->startOfDay(),
                'monthly' => Carbon::now()->copy()->subMonths(11)->startOfMonth()->startOfDay(),
                'yearly' => Carbon::now()->copy()->subYears(9)->startOfYear()->startOfDay(),
                default => Carbon::now()->copy()->subDays(29)->startOfDay(),
            };
        }

        if ($start->greaterThan($end)) {
            $tmp = $start;
            $start = $isHourly ? $end->copy()->startOfHour() : $end->copy()->startOfDay();
            $end = $isHourly ? $tmp->copy()->endOfHour() : $tmp->copy()->endOfDay();
        }

        return [$start, $end];
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder<Transaction> $base
     * @return array<int, array{date: string, successful_count: int, failed_count: int}>
     */
    private function successfulFailedCountsDaily(
        $base,
        string $paid,
        string $failed,
        string $cancelledPayment,
        Carbon $rangeStart,
        Carbon $rangeEnd
    ): array {
        $rows = (clone $base)
            ->selectRaw(
                'DATE(created_at) as bucket, ' .
                'SUM(CASE WHEN payment_status = ? THEN 1 ELSE 0 END) as successful_count, ' .
                'SUM(CASE WHEN payment_status IN (?, ?) THEN 1 ELSE 0 END) as failed_count',
                [$paid, $failed, $cancelledPayment]
            )
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('bucket')
            ->get();

        $byDay = [];
        foreach ($rows as $row) {
            $byDay[$row->bucket] = [
                'successful_count' => (int) $row->successful_count,
                'failed_count' => (int) $row->failed_count,
            ];
        }

        return $this->fillDailySuccessfulFailedCounts($rangeStart, $rangeEnd, $byDay);
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder<Transaction> $base
     * @return array<int, array{date: string, successful_count: int, failed_count: int}>
     */
    private function successfulFailedCountsWeekly(
        $base,
        string $paid,
        string $failed,
        string $cancelledPayment,
        Carbon $rangeStart,
        Carbon $rangeEnd
    ): array {
        $weekExpr = 'DATE_SUB(DATE(created_at), INTERVAL WEEKDAY(created_at) DAY)';

        $rows = (clone $base)
            ->selectRaw(
                "{$weekExpr} as bucket, " .
                'SUM(CASE WHEN payment_status = ? THEN 1 ELSE 0 END) as successful_count, ' .
                'SUM(CASE WHEN payment_status IN (?, ?) THEN 1 ELSE 0 END) as failed_count',
                [$paid, $failed, $cancelledPayment]
            )
            ->groupBy(DB::raw($weekExpr))
            ->orderBy('bucket')
            ->get();

        $byWeek = [];
        foreach ($rows as $row) {
            $key = Carbon::parse($row->bucket)->format('Y-m-d');
            $byWeek[$key] = [
                'successful_count' => (int) $row->successful_count,
                'failed_count' => (int) $row->failed_count,
            ];
        }

        $series = [];
        $cursor = $rangeStart->copy()->startOfWeek(Carbon::MONDAY);
        $endWeek = $rangeEnd->copy()->startOfWeek(Carbon::MONDAY);

        while ($cursor->lte($endWeek)) {
            $key = $cursor->format('Y-m-d');
            $row = $byWeek[$key] ?? ['successful_count' => 0, 'failed_count' => 0];
            $series[] = [
                'date' => $key,
                'successful_count' => $row['successful_count'],
                'failed_count' => $row['failed_count'],
            ];
            $cursor->addWeek();
        }

        return $series;
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder<Transaction> $base
     * @return array<int, array{date: string, successful_count: int, failed_count: int}>
     */
    private function successfulFailedCountsMonthly(
        $base,
        string $paid,
        string $failed,
        string $cancelledPayment,
        Carbon $rangeStart,
        Carbon $rangeEnd
    ): array {
        $rows = (clone $base)
            ->selectRaw(
                'DATE_FORMAT(created_at, "%Y-%m") as bucket, ' .
                'SUM(CASE WHEN payment_status = ? THEN 1 ELSE 0 END) as successful_count, ' .
                'SUM(CASE WHEN payment_status IN (?, ?) THEN 1 ELSE 0 END) as failed_count',
                [$paid, $failed, $cancelledPayment]
            )
            ->groupBy(DB::raw('DATE_FORMAT(created_at, "%Y-%m")'))
            ->orderBy('bucket')
            ->get();

        $byMonth = [];
        foreach ($rows as $row) {
            $byMonth[$row->bucket] = [
                'successful_count' => (int) $row->successful_count,
                'failed_count' => (int) $row->failed_count,
            ];
        }

        $series = [];
        $cursor = $rangeStart->copy()->startOfMonth();
        $endMonth = $rangeEnd->copy()->startOfMonth();

        while ($cursor->lte($endMonth)) {
            $key = $cursor->format('Y-m');
            $row = $byMonth[$key] ?? ['successful_count' => 0, 'failed_count' => 0];
            $series[] = [
                'date' => $key,
                'successful_count' => $row['successful_count'],
                'failed_count' => $row['failed_count'],
            ];
            $cursor->addMonth();
        }

        return $series;
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder<Transaction> $base
     * @return array<int, array{date: string, successful_count: int, failed_count: int}>
     */
    private function successfulFailedCountsYearly(
        $base,
        string $paid,
        string $failed,
        string $cancelledPayment,
        Carbon $rangeStart,
        Carbon $rangeEnd
    ): array {
        $rows = (clone $base)
            ->selectRaw(
                'YEAR(created_at) as bucket, ' .
                'SUM(CASE WHEN payment_status = ? THEN 1 ELSE 0 END) as successful_count, ' .
                'SUM(CASE WHEN payment_status IN (?, ?) THEN 1 ELSE 0 END) as failed_count',
                [$paid, $failed, $cancelledPayment]
            )
            ->groupBy(DB::raw('YEAR(created_at)'))
            ->orderBy('bucket')
            ->get();

        $byYear = [];
        foreach ($rows as $row) {
            $byYear[(string) $row->bucket] = [
                'successful_count' => (int) $row->successful_count,
                'failed_count' => (int) $row->failed_count,
            ];
        }

        $series = [];
        $y = $rangeStart->year;
        $yEnd = $rangeEnd->year;

        while ($y <= $yEnd) {
            $key = (string) $y;
            $row = $byYear[$key] ?? ['successful_count' => 0, 'failed_count' => 0];
            $series[] = [
                'date' => $key,
                'successful_count' => $row['successful_count'],
                'failed_count' => $row['failed_count'],
            ];
            $y++;
        }

        return $series;
    }

    /**
     * @param  array<string, array{successful_count: int, failed_count: int}>  $byDay
     * @return array<int, array{date: string, successful_count: int, failed_count: int}>
     */
    private function fillDailySuccessfulFailedCounts(Carbon $start, Carbon $end, array $byDay): array
    {
        $series = [];
        $cursor = $start->copy()->startOfDay();
        $endDay = $end->copy()->startOfDay();

        while ($cursor->lte($endDay)) {
            $key = $cursor->format('Y-m-d');
            $row = $byDay[$key] ?? ['successful_count' => 0, 'failed_count' => 0];
            $series[] = [
                'date' => $key,
                'successful_count' => $row['successful_count'],
                'failed_count' => $row['failed_count'],
            ];
            $cursor->addDay();
        }

        return $series;
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder<Transaction> $base
     * @return array<int, array{date: string, successful_count: int, failed_count: int}>
     */
    private function successfulFailedCountsHourly(
        $base,
        string $paid,
        string $failed,
        string $cancelledPayment,
        Carbon $rangeStart,
        Carbon $rangeEnd
    ): array {
        $hourExpr = $this->hourlyBucketExpression();

        $rows = (clone $base)
            ->selectRaw(
                "{$hourExpr} as bucket, " .
                'SUM(CASE WHEN payment_status = ? THEN 1 ELSE 0 END) as successful_count, ' .
                'SUM(CASE WHEN payment_status IN (?, ?) THEN 1 ELSE 0 END) as failed_count',
                [$paid, $failed, $cancelledPayment]
            )
            ->groupBy(DB::raw($hourExpr))
            ->orderBy('bucket')
            ->get();

        $byHour = [];
        foreach ($rows as $row) {
            $byHour[$row->bucket] = [
                'successful_count' => (int) $row->successful_count,
                'failed_count' => (int) $row->failed_count,
            ];
        }

        return $this->fillHourlySuccessfulFailedCounts($rangeStart, $rangeEnd, $byHour);
    }

    /**
     * @param  array<string, array{successful_count: int, failed_count: int}>  $byHour
     * @return array<int, array{date: string, successful_count: int, failed_count: int}>
     */
    private function fillHourlySuccessfulFailedCounts(Carbon $start, Carbon $end, array $byHour): array
    {
        $series = [];
        $cursor = $start->copy()->startOfHour();
        $endHour = $end->copy()->startOfHour();

        while ($cursor->lte($endHour)) {
            $key = $cursor->format('Y-m-d H:00');
            $row = $byHour[$key] ?? ['successful_count' => 0, 'failed_count' => 0];
            $series[] = [
                'date' => $key,
                'successful_count' => $row['successful_count'],
                'failed_count' => $row['failed_count'],
            ];
            $cursor->addHour();
        }

        return $series;
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder<Transaction> $base
     * @return array<int, array{date: string, total_amount: float}>
     */
    private function seriesHourly($base, Carbon $rangeStart, Carbon $rangeEnd): array
    {
        $hourExpr = $this->hourlyBucketExpression();

        $rows = (clone $base)
            ->selectRaw("{$hourExpr} as bucket")
            ->selectRaw($this->revenueSumSelectSql())
            ->groupBy(DB::raw($hourExpr))
            ->orderBy('bucket')
            ->get();

        $byHour = [];
        foreach ($rows as $row) {
            $byHour[$row->bucket] = (float) $row->total_amount;
        }

        return $this->fillHourlyAmountSeries($rangeStart, $rangeEnd, $byHour);
    }

    /**
     * @param  array<string, float>  $byHour
     * @return array<int, array{date: string, total_amount: float}>
     */
    private function fillHourlyAmountSeries(Carbon $start, Carbon $end, array $byHour): array
    {
        $series = [];
        $cursor = $start->copy()->startOfHour();
        $endHour = $end->copy()->startOfHour();

        while ($cursor->lte($endHour)) {
            $key = $cursor->format('Y-m-d H:00');
            $series[] = [
                'date' => $key,
                'total_amount' => $byHour[$key] ?? 0.0,
            ];
            $cursor->addHour();
        }

        return $series;
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder<Transaction> $base
     * @return array<int, array{date: string, total_amount: float}>
     */
    private function seriesDaily($base, Carbon $rangeStart, Carbon $rangeEnd): array
    {
        $rows = (clone $base)
            ->selectRaw('DATE(created_at) as bucket')
            ->selectRaw($this->revenueSumSelectSql())
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('bucket')
            ->get();

        $byDay = [];
        foreach ($rows as $row) {
            $byDay[$row->bucket] = (float) $row->total_amount;
        }

        return $this->fillDailySeries($rangeStart, $rangeEnd, $byDay);
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder $base
     * @return array<int, array{date: string, count: int}>
     */
    private function countSeriesHourly($base, Carbon $rangeStart, Carbon $rangeEnd): array
    {
        $hourExpr = $this->hourlyBucketExpression();

        $rows = (clone $base)
            ->selectRaw("{$hourExpr} as bucket")
            ->selectRaw('COUNT(*) as c')
            ->groupBy(DB::raw($hourExpr))
            ->orderBy('bucket')
            ->get();

        $byHour = [];
        foreach ($rows as $row) {
            $byHour[$row->bucket] = (int) $row->c;
        }

        $series = [];
        $cursor = $rangeStart->copy()->startOfHour();
        $endHour = $rangeEnd->copy()->startOfHour();

        while ($cursor->lte($endHour)) {
            $key = $cursor->format('Y-m-d H:00');
            $series[] = [
                'date' => $key,
                'count' => $byHour[$key] ?? 0,
            ];
            $cursor->addHour();
        }

        return $series;
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder $base
     * @return array<int, array{date: string, count: int}>
     */
    private function countSeriesDaily($base, Carbon $rangeStart, Carbon $rangeEnd): array
    {
        $rows = (clone $base)
            ->selectRaw('DATE(created_at) as bucket')
            ->selectRaw('COUNT(*) as c')
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('bucket')
            ->get();

        $byDay = [];
        foreach ($rows as $row) {
            $byDay[$row->bucket] = (int) $row->c;
        }

        $series = [];
        $cursor = $rangeStart->copy()->startOfDay();
        $endDay = $rangeEnd->copy()->startOfDay();

        while ($cursor->lte($endDay)) {
            $key = $cursor->format('Y-m-d');
            $series[] = [
                'date' => $key,
                'count' => $byDay[$key] ?? 0,
            ];
            $cursor->addDay();
        }

        return $series;
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder $base
     * @return array<int, array{date: string, count: int}>
     */
    private function countSeriesWeekly($base, Carbon $rangeStart, Carbon $rangeEnd): array
    {
        $weekExpr = 'DATE_SUB(DATE(created_at), INTERVAL WEEKDAY(created_at) DAY)';

        $rows = (clone $base)
            ->selectRaw("{$weekExpr} as bucket")
            ->selectRaw('COUNT(*) as c')
            ->groupBy(DB::raw($weekExpr))
            ->orderBy('bucket')
            ->get();

        $byWeek = [];
        foreach ($rows as $row) {
            $key = Carbon::parse($row->bucket)->format('Y-m-d');
            $byWeek[$key] = (int) $row->c;
        }

        $series = [];
        $cursor = $rangeStart->copy()->startOfWeek(Carbon::MONDAY);
        $endWeek = $rangeEnd->copy()->startOfWeek(Carbon::MONDAY);

        while ($cursor->lte($endWeek)) {
            $key = $cursor->format('Y-m-d');
            $series[] = [
                'date' => $key,
                'count' => $byWeek[$key] ?? 0,
            ];
            $cursor->addWeek();
        }

        return $series;
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder $base
     * @return array<int, array{date: string, count: int}>
     */
    private function countSeriesMonthly($base, Carbon $rangeStart, Carbon $rangeEnd): array
    {
        $rows = (clone $base)
            ->selectRaw('DATE_FORMAT(created_at, "%Y-%m") as bucket')
            ->selectRaw('COUNT(*) as c')
            ->groupBy(DB::raw('DATE_FORMAT(created_at, "%Y-%m")'))
            ->orderBy('bucket')
            ->get();

        $byMonth = [];
        foreach ($rows as $row) {
            $byMonth[$row->bucket] = (int) $row->c;
        }

        $series = [];
        $cursor = $rangeStart->copy()->startOfMonth();
        $endMonth = $rangeEnd->copy()->startOfMonth();

        while ($cursor->lte($endMonth)) {
            $key = $cursor->format('Y-m');
            $series[] = [
                'date' => $key,
                'count' => $byMonth[$key] ?? 0,
            ];
            $cursor->addMonth();
        }

        return $series;
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder $base
     * @return array<int, array{date: string, count: int}>
     */
    private function countSeriesYearly($base, Carbon $rangeStart, Carbon $rangeEnd): array
    {
        $rows = (clone $base)
            ->selectRaw('YEAR(created_at) as bucket')
            ->selectRaw('COUNT(*) as c')
            ->groupBy(DB::raw('YEAR(created_at)'))
            ->orderBy('bucket')
            ->get();

        $byYear = [];
        foreach ($rows as $row) {
            $byYear[(string) $row->bucket] = (int) $row->c;
        }

        $series = [];
        $y = $rangeStart->year;
        $yEnd = $rangeEnd->year;

        while ($y <= $yEnd) {
            $key = (string) $y;
            $series[] = [
                'date' => $key,
                'count' => $byYear[$key] ?? 0,
            ];
            $y++;
        }

        return $series;
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder<Transaction> $base
     * @return array<int, array{date: string, total_amount: float}>
     */
    private function seriesWeekly($base, Carbon $rangeStart, Carbon $rangeEnd): array
    {
        $weekExpr = 'DATE_SUB(DATE(created_at), INTERVAL WEEKDAY(created_at) DAY)';

        $rows = (clone $base)
            ->selectRaw("{$weekExpr} as bucket")
            ->selectRaw($this->revenueSumSelectSql())
            ->groupBy(DB::raw($weekExpr))
            ->orderBy('bucket')
            ->get();

        $byWeek = [];
        foreach ($rows as $row) {
            $key = Carbon::parse($row->bucket)->format('Y-m-d');
            $byWeek[$key] = (float) $row->total_amount;
        }

        $cursor = $rangeStart->copy()->startOfWeek(Carbon::MONDAY);
        $endWeek = $rangeEnd->copy()->startOfWeek(Carbon::MONDAY);
        $series = [];

        while ($cursor->lte($endWeek)) {
            $key = $cursor->format('Y-m-d');
            $series[] = [
                'date' => $key,
                'total_amount' => $byWeek[$key] ?? 0.0,
            ];
            $cursor->addWeek();
        }

        return $series;
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder<Transaction> $base
     * @return array<int, array{date: string, total_amount: float}>
     */
    private function seriesMonthly($base, Carbon $rangeStart, Carbon $rangeEnd): array
    {
        $rows = (clone $base)
            ->selectRaw('DATE_FORMAT(created_at, "%Y-%m") as bucket')
            ->selectRaw($this->revenueSumSelectSql())
            ->groupBy(DB::raw('DATE_FORMAT(created_at, "%Y-%m")'))
            ->orderBy('bucket')
            ->get();

        $byMonth = [];
        foreach ($rows as $row) {
            $byMonth[$row->bucket] = (float) $row->total_amount;
        }

        $series = [];
        $cursor = $rangeStart->copy()->startOfMonth();
        $endMonth = $rangeEnd->copy()->startOfMonth();

        while ($cursor->lte($endMonth)) {
            $key = $cursor->format('Y-m');
            $series[] = [
                'date' => $key,
                'total_amount' => $byMonth[$key] ?? 0.0,
            ];
            $cursor->addMonth();
        }

        return $series;
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder<Transaction> $base
     * @return array<int, array{date: string, total_amount: float}>
     */
    private function seriesYearly($base, Carbon $rangeStart, Carbon $rangeEnd): array
    {
        $rows = (clone $base)
            ->selectRaw('YEAR(created_at) as bucket')
            ->selectRaw($this->revenueSumSelectSql())
            ->groupBy(DB::raw('YEAR(created_at)'))
            ->orderBy('bucket')
            ->get();

        $byYear = [];
        foreach ($rows as $row) {
            $byYear[(string) $row->bucket] = (float) $row->total_amount;
        }

        $series = [];
        $y = $rangeStart->year;
        $yEnd = $rangeEnd->year;

        while ($y <= $yEnd) {
            $key = (string) $y;
            $series[] = [
                'date' => $key,
                'total_amount' => $byYear[$key] ?? 0.0,
            ];
            $y++;
        }

        return $series;
    }

    /**
     * @param  array<string, float>  $byDay
     * @return array<int, array{date: string, total_amount: float}>
     */
    private function fillDailySeries(Carbon $start, Carbon $end, array $byDay): array
    {
        $series = [];
        $cursor = $start->copy()->startOfDay();
        $endDay = $end->copy()->startOfDay();

        while ($cursor->lte($endDay)) {
            $key = $cursor->format('Y-m-d');
            $series[] = [
                'date' => $key,
                'total_amount' => $byDay[$key] ?? 0.0,
            ];
            $cursor->addDay();
        }

        return $series;
    }

    /**
     * Paid revenue per event per time bucket (stacked bar). Events sorted by name; amounts align with events order.
     *
     * @return array{granularity: string, start_date: string, end_date: string, events: array<int, array{event_uuid: string, event_name: string}>, series: array<int, array{date: string, amounts: array<int, float>}>}
     */
    public function getRevenuePerEventSeries(
        string $granularity,
        ?string $organizationUuid,
        ?string $startDate,
        ?string $endDate,
        bool $merchantRevenueOnly = false,
    ): array {
        return $this->withMerchantRevenueBasis($merchantRevenueOnly, function () use ($granularity, $organizationUuid, $startDate, $endDate) {
            [$start, $end] = $this->resolveSeriesDateBounds($granularity, $startDate, $endDate);

            $matrix = [];
            $eventNames = [];

            $base = $this->revenuePerEventBaseQuery($organizationUuid, $start, $end);

            $rows = match ($granularity) {
                'hourly' => $this->fetchRevenuePerEventRowsHourly($base),
                'daily' => $this->fetchRevenuePerEventRowsDaily($base),
                'weekly' => $this->fetchRevenuePerEventRowsWeekly($base),
                'monthly' => $this->fetchRevenuePerEventRowsMonthly($base),
                'yearly' => $this->fetchRevenuePerEventRowsYearly($base),
                default => $this->fetchRevenuePerEventRowsDaily($base),
            };

            foreach ($rows as $row) {
                $bucketKey = $this->normalizeRevenuePerEventBucketKey($granularity, $row->bucket);
                $uuid = $row->event_uuid;
                $matrix[$bucketKey][$uuid] = (float) $row->total_amount;
                $eventNames[$uuid] = $row->event_name;
            }

            $orderedUuids = $this->orderEventUuidsByName($eventNames);

            $eventsPayload = array_map(fn (string $uuid) => [
                'event_uuid' => $uuid,
                'event_name' => $eventNames[$uuid],
            ], $orderedUuids);

            $series = match ($granularity) {
                'hourly' => $this->fillRevenuePerEventHourlySeries($matrix, $orderedUuids, $start, $end),
                'daily' => $this->fillRevenuePerEventDailySeries($matrix, $orderedUuids, $start, $end),
                'weekly' => $this->fillRevenuePerEventWeeklySeries($matrix, $orderedUuids, $start, $end),
                'monthly' => $this->fillRevenuePerEventMonthlySeries($matrix, $orderedUuids, $start, $end),
                'yearly' => $this->fillRevenuePerEventYearlySeries($matrix, $orderedUuids, $start, $end),
                default => $this->fillRevenuePerEventDailySeries($matrix, $orderedUuids, $start, $end),
            };

            return [
                'granularity' => $granularity,
                'start_date' => $this->formatSeriesRangeBound($start, $granularity),
                'end_date' => $this->formatSeriesRangeBound($end, $granularity),
                'events' => $eventsPayload,
                'series' => $series,
            ];
        });
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<Transaction>
     */
    private function revenuePerEventBaseQuery(?string $organizationUuid, Carbon $start, Carbon $end)
    {
        $q = $this->transaction->newQuery()
            ->join('events', function ($join) {
                $join->on('events.uuid', '=', 'transactions.event_uuid')
                    ->whereNull('events.deleted_at');
            })
            ->where('transactions.payment_status', Transaction::PAYMENT_STATUS['PAID'])
            ->whereBetween('transactions.created_at', [$start, $end])
            ->whereNotNull('transactions.event_uuid');

        if ($organizationUuid) {
            $q->where('transactions.organization_uuid', $organizationUuid);
        }

        return $q;
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder<Transaction> $base
     * @return \Illuminate\Support\Collection<int, object{bucket: mixed, event_uuid: string, event_name: string, total_amount: string|float}>
     */
    private function fetchRevenuePerEventRowsHourly($base)
    {
        $hourExpr = $this->hourlyBucketExpression('transactions.created_at');

        return (clone $base)
            ->selectRaw("{$hourExpr} as bucket")
            ->selectRaw('transactions.event_uuid')
            ->selectRaw('events.event_name')
            ->selectRaw($this->revenueSumSelectSql('transactions'))
            ->groupBy(DB::raw($hourExpr))
            ->groupBy('transactions.event_uuid')
            ->groupBy('events.event_name')
            ->orderBy('bucket')
            ->get();
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder<Transaction> $base
     * @return \Illuminate\Support\Collection<int, object{bucket: mixed, event_uuid: string, event_name: string, total_amount: string|float}>
     */
    private function fetchRevenuePerEventRowsDaily($base)
    {
        return (clone $base)
            ->selectRaw('DATE(transactions.created_at) as bucket')
            ->selectRaw('transactions.event_uuid')
            ->selectRaw('events.event_name')
            ->selectRaw($this->revenueSumSelectSql('transactions'))
            ->groupBy(DB::raw('DATE(transactions.created_at)'))
            ->groupBy('transactions.event_uuid')
            ->groupBy('events.event_name')
            ->orderBy('bucket')
            ->get();
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder<Transaction> $base
     * @return \Illuminate\Support\Collection<int, object{bucket: mixed, event_uuid: string, event_name: string, total_amount: string|float}>
     */
    private function fetchRevenuePerEventRowsWeekly($base)
    {
        $weekExpr = 'DATE_SUB(DATE(transactions.created_at), INTERVAL WEEKDAY(transactions.created_at) DAY)';

        return (clone $base)
            ->selectRaw("{$weekExpr} as bucket")
            ->selectRaw('transactions.event_uuid')
            ->selectRaw('events.event_name')
            ->selectRaw($this->revenueSumSelectSql('transactions'))
            ->groupBy(DB::raw($weekExpr))
            ->groupBy('transactions.event_uuid')
            ->groupBy('events.event_name')
            ->orderBy('bucket')
            ->get();
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder<Transaction> $base
     * @return \Illuminate\Support\Collection<int, object{bucket: mixed, event_uuid: string, event_name: string, total_amount: string|float}>
     */
    private function fetchRevenuePerEventRowsMonthly($base)
    {
        return (clone $base)
            ->selectRaw('DATE_FORMAT(transactions.created_at, "%Y-%m") as bucket')
            ->selectRaw('transactions.event_uuid')
            ->selectRaw('events.event_name')
            ->selectRaw($this->revenueSumSelectSql('transactions'))
            ->groupBy(DB::raw('DATE_FORMAT(transactions.created_at, "%Y-%m")'))
            ->groupBy('transactions.event_uuid')
            ->groupBy('events.event_name')
            ->orderBy('bucket')
            ->get();
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder<Transaction> $base
     * @return \Illuminate\Support\Collection<int, object{bucket: mixed, event_uuid: string, event_name: string, total_amount: string|float}>
     */
    private function fetchRevenuePerEventRowsYearly($base)
    {
        return (clone $base)
            ->selectRaw('YEAR(transactions.created_at) as bucket')
            ->selectRaw('transactions.event_uuid')
            ->selectRaw('events.event_name')
            ->selectRaw($this->revenueSumSelectSql('transactions'))
            ->groupBy(DB::raw('YEAR(transactions.created_at)'))
            ->groupBy('transactions.event_uuid')
            ->groupBy('events.event_name')
            ->orderBy('bucket')
            ->get();
    }

    private function normalizeRevenuePerEventBucketKey(string $granularity, mixed $bucket): string
    {
        return match ($granularity) {
            'hourly' => Carbon::parse($bucket)->format('Y-m-d H:00'),
            'daily', 'weekly' => Carbon::parse($bucket)->format('Y-m-d'),
            'monthly' => (string) $bucket,
            'yearly' => (string) $bucket,
            default => Carbon::parse($bucket)->format('Y-m-d'),
        };
    }

    /**
     * @param  array<string, string>  $eventNames
     * @return array<int, string>
     */
    private function orderEventUuidsByName(array $eventNames): array
    {
        $uuids = array_keys($eventNames);
        usort($uuids, fn ($a, $b) => strcmp($eventNames[$a], $eventNames[$b]));

        return $uuids;
    }

    /**
     * @param  array<string, array<string, float>>  $matrix
     * @param  array<int, string>  $orderedUuids
     * @return array<int, array{date: string, amounts: array<int, float>}>
     */
    private function buildAmountsForBucket(array $matrix, array $orderedUuids, string $bucketKey): array
    {
        $amounts = [];
        foreach ($orderedUuids as $uuid) {
            $amounts[] = (float) ($matrix[$bucketKey][$uuid] ?? 0.0);
        }

        return [
            'date' => $bucketKey,
            'amounts' => $amounts,
        ];
    }

    /**
     * @param  array<string, array<string, float>>  $matrix
     * @param  array<int, string>  $orderedUuids
     * @return array<int, array{date: string, amounts: array<int, float>}>
     */
    private function fillRevenuePerEventHourlySeries(
        array $matrix,
        array $orderedUuids,
        Carbon $rangeStart,
        Carbon $rangeEnd
    ): array {
        $series = [];
        $cursor = $rangeStart->copy()->startOfHour();
        $endHour = $rangeEnd->copy()->startOfHour();

        while ($cursor->lte($endHour)) {
            $key = $cursor->format('Y-m-d H:00');
            $series[] = $this->buildAmountsForBucket($matrix, $orderedUuids, $key);
            $cursor->addHour();
        }

        return $series;
    }

    /**
     * @param  array<string, array<string, float>>  $matrix
     * @param  array<int, string>  $orderedUuids
     * @return array<int, array{date: string, amounts: array<int, float>}>
     */
    private function fillRevenuePerEventDailySeries(
        array $matrix,
        array $orderedUuids,
        Carbon $rangeStart,
        Carbon $rangeEnd
    ): array {
        $series = [];
        $cursor = $rangeStart->copy()->startOfDay();
        $endDay = $rangeEnd->copy()->startOfDay();

        while ($cursor->lte($endDay)) {
            $key = $cursor->format('Y-m-d');
            $series[] = $this->buildAmountsForBucket($matrix, $orderedUuids, $key);
            $cursor->addDay();
        }

        return $series;
    }

    /**
     * @param  array<string, array<string, float>>  $matrix
     * @param  array<int, string>  $orderedUuids
     * @return array<int, array{date: string, amounts: array<int, float>}>
     */
    private function fillRevenuePerEventWeeklySeries(
        array $matrix,
        array $orderedUuids,
        Carbon $rangeStart,
        Carbon $rangeEnd
    ): array {
        $series = [];
        $cursor = $rangeStart->copy()->startOfWeek(Carbon::MONDAY);
        $endWeek = $rangeEnd->copy()->startOfWeek(Carbon::MONDAY);

        while ($cursor->lte($endWeek)) {
            $key = $cursor->format('Y-m-d');
            $series[] = $this->buildAmountsForBucket($matrix, $orderedUuids, $key);
            $cursor->addWeek();
        }

        return $series;
    }

    /**
     * @param  array<string, array<string, float>>  $matrix
     * @param  array<int, string>  $orderedUuids
     * @return array<int, array{date: string, amounts: array<int, float>}>
     */
    private function fillRevenuePerEventMonthlySeries(
        array $matrix,
        array $orderedUuids,
        Carbon $rangeStart,
        Carbon $rangeEnd
    ): array {
        $series = [];
        $cursor = $rangeStart->copy()->startOfMonth();
        $endMonth = $rangeEnd->copy()->startOfMonth();

        while ($cursor->lte($endMonth)) {
            $key = $cursor->format('Y-m');
            $series[] = $this->buildAmountsForBucket($matrix, $orderedUuids, $key);
            $cursor->addMonth();
        }

        return $series;
    }

    /**
     * @param  array<string, array<string, float>>  $matrix
     * @param  array<int, string>  $orderedUuids
     * @return array<int, array{date: string, amounts: array<int, float>}>
     */
    private function fillRevenuePerEventYearlySeries(
        array $matrix,
        array $orderedUuids,
        Carbon $rangeStart,
        Carbon $rangeEnd
    ): array {
        $series = [];
        $y = $rangeStart->year;
        $yEnd = $rangeEnd->year;

        while ($y <= $yEnd) {
            $key = (string) $y;
            $series[] = $this->buildAmountsForBucket($matrix, $orderedUuids, $key);
            $y++;
        }

        return $series;
    }

    public function cancelledCheckouts(string $startDate, string $endDate): string
    {
        $transactions = $this->transaction->whereBetween('created_at', [
            Carbon::parse($startDate)->startOfDay(),
            Carbon::parse($endDate)->endOfDay()
        ])->where('order_status', Transaction::ORDER_STATUS['CANCELLED'])
        ->with([
            'user',
            'event',
            'transactionOrders',
            'transactionOrders.eventTicket'
        ])->get();

        $data = [];

        $headers = [
            'Event Name',
            'Event Schedule',
            'User',
            'Email',
            'Ticket Details',
            'Discount',
            'Discount Code',
            'Total Amount',
            'Payment Method',
            'Date'
        ];

        $data[] = $headers;

        foreach ($transactions as $transaction) {
            $ticketDetails = "";
            $transactionOrders = $transaction->transactionOrders;
            foreach ($transactionOrders as $transactionOrder) {
                $ticketDetails .= $transactionOrder->eventTicket->name . " (qty:" . $transactionOrder->quantity . ")";
            }
            $data[] = [
                $transaction->event->event_name,
                $transaction->schedule?->date_from ? Carbon::parse($transaction->schedule?->date_from)->format('Y/m/d') . ($transaction->scheduleTime ? ' - ' . Carbon::parse($transaction->scheduleTime?->time_start)->format('H:i') . ' - ' . Carbon::parse($transaction->scheduleTime?->time_end)->format('H:i') : '') : 'N/A',
                $transaction->user->full_name ?? 'N/A',
                $transaction->user->email ?? 'N/A',
                $ticketDetails,
                number_format($transaction->discount, 2),
                $transaction->promo_code_uuid ? $transaction->promoCode->code : '',
                number_format($transaction->total_amount, 2),
                $transaction->payment_provider ?? 'N/A',
                Carbon::parse($transaction->created_at)->format('Y/m/d H:i')
            ];
        }

        // Generate CSV content
        $csvContent = '';
        foreach ($data as $row) {
            $csvContent .= '"' . implode('","', $row) . '"' . "\n";
        }

        return $csvContent;
    }

    /**
     * Pie chart: total paid revenue per event (sum of transactions.total_amount).
     *
     * @return array<int, array{event_uuid: string, event_name: string, total_amount: float}>
     */
    public function getRevenueByEventTotals(?string $organizationUuid): array
    {
        $rows = $this->transaction->newQuery()
            ->join('events', function ($join) {
                $join->on('events.uuid', '=', 'transactions.event_uuid')
                    ->whereNull('events.deleted_at');
            })
            ->where('transactions.payment_status', Transaction::PAYMENT_STATUS['PAID'])
            ->where('transactions.total_amount', '>', 0)
            ->when($organizationUuid, fn ($q) => $q->where('transactions.organization_uuid', $organizationUuid))
            ->groupBy('transactions.event_uuid', 'events.event_name')
            ->orderByDesc(DB::raw('SUM(transactions.total_amount)'))
            ->limit(10)
            ->get([
                'transactions.event_uuid as event_uuid',
                'events.event_name as event_name',
                DB::raw('SUM(transactions.total_amount) as total_amount'),
            ]);

        return $rows
            ->map(fn ($r) => [
                'event_uuid' => (string) $r->event_uuid,
                'event_name' => (string) $r->event_name,
                'total_amount' => (float) $r->total_amount,
            ])
            ->values()
            ->all();
    }

    /**
     * Pie chart: customers split into New (exactly 1 paid tx) vs Repeat (2+ paid tx).
     *
     * "Paid tx" means payment_status=PAID and total_amount>0.
     *
     * @return array{new_customers: int, repeat_customers: int}
     */
    public function getNewVsRepeatCustomerCounts(?string $organizationUuid): array
    {
        $perUser = DB::table('transactions')
            ->select('user_uuid', DB::raw('COUNT(*) as paid_count'))
            ->whereNotNull('event_uuid')
            ->where('payment_status', Transaction::PAYMENT_STATUS['PAID'])
            ->where('total_amount', '>', 0)
            ->when($organizationUuid, fn ($q) => $q->where('organization_uuid', $organizationUuid))
            ->groupBy('user_uuid');

        $counts = DB::query()
            ->fromSub($perUser, 't')
            ->selectRaw('SUM(CASE WHEN paid_count = 1 THEN 1 ELSE 0 END) as new_customers')
            ->selectRaw('SUM(CASE WHEN paid_count >= 2 THEN 1 ELSE 0 END) as repeat_customers')
            ->first();

        return [
            'new_customers' => (int) ($counts->new_customers ?? 0),
            'repeat_customers' => (int) ($counts->repeat_customers ?? 0),
        ];
    }
}
