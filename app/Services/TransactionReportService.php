<?php

namespace App\Services;

use App\Constants\GeneralConstants;
use App\Http\Repositories\TransactionRepository;
use App\Models\Event;
use App\Models\Transaction;
use App\Models\TransactionOrder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class TransactionReportService
{
    public const DATE_PURCHASE = 'purchase';

    public const DATE_VISIT = 'visit';

    private const HEADER_ROW = 5;

    private const COLUMNS = [
        'Order #' => 22,
        'Customer' => 24,
        'Email' => 30,
        'Phone' => 16,
        'Attraction' => 32,
        'Tickets' => 40,
        'Qty' => 8,
        'Date of Visit' => 16,
        'Payment Method' => 19,
        'Payment Status' => 18,
        'Order Status' => 16,
        'Subtotal' => 13,
        'Discount' => 13,
        'Promo Discount' => 18,
        'Taxes & Fees' => 16,
        'Total' => 14,
        'Ordered At' => 22,
        'Paid At' => 22,
    ];

    public function __construct(private readonly TransactionRepository $transactionRepository)
    {
    }

    /**
     * Builds the transactions report for the given filters.
     *
     * @param  array{event_uuid?: string|null, order_status?: string|null, date_type?: string|null, start_date?: string|null, end_date?: string|null}  $filters
     * @return array{path: string, file_name: string, count: int}
     */
    public function export(array $filters): array
    {
        $rows = [];
        $this->query($filters)->chunk(500, function ($transactions) use (&$rows) {
            foreach ($transactions as $transaction) {
                $rows[] = $this->row($transaction);
            }
        });
        $count = count($rows);
        $countLabel = $count . ' ' . Str::plural('transaction', $count);

        $writer = (new XlsxWriter('Transactions'))
            ->setColumnWidths(array_values(self::COLUMNS))
            ->freezeRows(self::HEADER_ROW)
            ->filterFromRow(self::HEADER_ROW);

        $writer->addRow(['PAEC Transactions Report'], XlsxWriter::STYLE_TITLE);
        $writer->addRow([$this->filterSummary($filters)], XlsxWriter::STYLE_NOTE);
        $writer->addRow(
            ['Generated ' . now(GeneralConstants::TIMEZONE)->format('M j, Y g:i A') . '   ·   ' . $countLabel],
            XlsxWriter::STYLE_NOTE,
        );
        $writer->addRow([]);
        $writer->addRow(array_keys(self::COLUMNS), XlsxWriter::STYLE_HEADER);

        foreach ($rows as $row) {
            $writer->addRow($row);
        }

        $sum = fn (int $column) => [round(array_sum(array_map(fn ($row) => $row[$column][0], $rows)), 2), XlsxWriter::STYLE_TOTAL_MONEY];
        $writer->addRow([
            'Total', '', '', '', '', $countLabel, array_sum(array_column($rows, 6)), '', '', '', '',
            $sum(11), $sum(12), $sum(13), $sum(14), $sum(15), '', '',
        ], XlsxWriter::STYLE_TOTAL);

        $path = tempnam(sys_get_temp_dir(), 'paec-report-');
        $writer->save($path);

        return [
            'path' => $path,
            'file_name' => 'transactions_report_' . now()->format('Y-m-d_His') . '.xlsx',
            'count' => $count,
        ];
    }

    private function query(array $filters): Builder
    {
        $dateFilter = ($filters['date_type'] ?? self::DATE_PURCHASE) === self::DATE_VISIT ? 'visit' : 'purchase';

        return $this->transactionRepository->getAll(array_filter([
            'event_uuid' => $filters['event_uuid'] ?? null,
            'order_status' => $filters['order_status'] ?? null,
            "{$dateFilter}_start_date" => $filters['start_date'] ?? null,
            "{$dateFilter}_end_date" => $filters['end_date'] ?? null,
        ]))->orderBy('uuid');
    }

    /**
     * @return list<mixed>
     */
    private function row(Transaction $transaction): array
    {
        $orders = $transaction->transactionOrders;
        $visitDate = $orders->pluck('valid_until')->filter()->min();
        $money = fn ($amount) => [round((float) $amount, 2), XlsxWriter::STYLE_MONEY];
        $local = fn ($date) => $date ? CarbonImmutable::parse($date)->setTimezone(GeneralConstants::TIMEZONE) : null;

        return [
            $transaction->order_number,
            trim(($transaction->user?->first_name ?? '') . ' ' . ($transaction->user?->last_name ?? '')),
            $transaction->user?->email,
            $transaction->user?->phone_number,
            $transaction->event?->event_name,
            $orders->map(fn (TransactionOrder $order) => $order->quantity . ' × ' . ($order->eventTicket?->name ?? 'Ticket'))
                ->implode(', '),
            (int) $orders->sum('quantity'),
            $visitDate ? [CarbonImmutable::parse($visitDate->toDateString(), 'UTC'), XlsxWriter::STYLE_DATE] : null,
            $transaction->payment_provider ? Str::headline($transaction->payment_provider) : null,
            Str::headline((string) $transaction->payment_status),
            Str::headline((string) $transaction->order_status),
            $money((float) $transaction->sub_total + (float) $transaction->markup_amount + (float) $transaction->markup_discount),
            $money((float) $transaction->discount + (float) $transaction->markup_discount),
            $money($transaction->promo_code_discount),
            $money($transaction->tax_amount),
            $money($transaction->total_amount),
            [$local($transaction->created_at), XlsxWriter::STYLE_DATETIME],
            [$local($transaction->paid_at), XlsxWriter::STYLE_DATETIME],
        ];
    }

    private function filterSummary(array $filters): string
    {
        $attraction = ! empty($filters['event_uuid'])
            ? (Event::query()->where('uuid', $filters['event_uuid'])->value('event_name') ?? 'Unknown attraction')
            : 'All attractions';

        $dateLabel = ($filters['date_type'] ?? self::DATE_PURCHASE) === self::DATE_VISIT ? 'Date of visit' : 'Purchase date';
        $format = fn (string $date) => CarbonImmutable::parse($date)->format('M j, Y');
        $start = $filters['start_date'] ?? null;
        $end = $filters['end_date'] ?? null;
        $range = match (true) {
            $start && $end => $format($start) . ' – ' . $format($end),
            (bool) $start => 'From ' . $format($start),
            (bool) $end => 'Until ' . $format($end),
            default => 'Any date',
        };

        $status = ! empty($filters['order_status']) ? Str::headline($filters['order_status']) : 'All statuses';

        return "Attraction: {$attraction}   ·   {$dateLabel}: {$range}   ·   Status: {$status}";
    }
}
