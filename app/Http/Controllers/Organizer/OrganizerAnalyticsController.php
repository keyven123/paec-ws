<?php

namespace App\Http\Controllers\Organizer;

use App\Constants\GeneralConstants;
use App\Http\Controllers\Controller;
use App\Http\Repositories\AnalyticsRepository;
use App\Http\Requests\Analytics\TransactionRevenueSeriesRequest;
use App\Models\Event;
use App\Models\Transaction;
use App\Services\Organizer\OrganizerAccountingBalanceService;
use App\Services\TicketPurchasePricingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Request;
use Carbon\Carbon;

class OrganizerAnalyticsController extends Controller
{
    public function __construct(
        protected AnalyticsRepository $analyticsRepository,
        protected OrganizerAccountingBalanceService $accountingBalanceService,
    ) {
    }

    private function merchantSalesForTransaction(Transaction $tx): float
    {
        $tx->loadMissing('organization');
        $rate = $this->accountingBalanceService->commissionRate($tx->organization);

        return TicketPurchasePricingService::transactionMerchantSalesTotal($tx, $rate);
    }

    private function transactionQueryBuilder(Builder|Relation $query): Builder
    {
        return $query instanceof Relation ? $query->getQuery() : $query;
    }

    private function sumPaidMerchantSales(Builder|Relation $query): float
    {
        $sum = 0.0;

        (clone $this->transactionQueryBuilder($query))
            ->where('payment_status', Transaction::PAYMENT_STATUS['PAID'])
            ->with(['event', 'transactionOrders.eventTicket', 'affiliateConversion', 'organization'])
            ->orderBy('uuid')
            ->chunkById(200, function ($transactions) use (&$sum) {
                foreach ($transactions as $tx) {
                    /** @var Transaction $tx */
                    $sum += $this->merchantSalesForTransaction($tx);
                }
            }, 'uuid');

        return round($sum, 2);
    }

    /**
     * @return array<string, float>
     */
    private function aggregateNetSellingByMonth(Builder|Relation $query, int $monthsBack = 6): array
    {
        $cutoff = now()->subMonths($monthsBack)->startOfMonth();
        $totals = [];

        (clone $this->transactionQueryBuilder($query))
            ->where('payment_status', Transaction::PAYMENT_STATUS['PAID'])
            ->where('created_at', '>=', $cutoff)
            ->with(['event', 'transactionOrders.eventTicket', 'affiliateConversion', 'organization'])
            ->orderBy('uuid')
            ->chunkById(200, function ($transactions) use (&$totals) {
                foreach ($transactions as $tx) {
                    /** @var Transaction $tx */
                    $monthKey = $tx->created_at->format('Y-m');
                    $totals[$monthKey] = ($totals[$monthKey] ?? 0.0)
                        + $this->merchantSalesForTransaction($tx);
                }
            }, 'uuid');

        foreach ($totals as $month => $amount) {
            $totals[$month] = round($amount, 2);
        }

        return $totals;
    }

    /**
     * @return array<string, array{total_sales: float, order_count: int}>
     */
    private function aggregateNetSellingByEvent(Builder|Relation $query, int $limit = 10): array
    {
        $byEvent = [];

        (clone $this->transactionQueryBuilder($query))
            ->where('payment_status', Transaction::PAYMENT_STATUS['PAID'])
            ->whereNotNull('event_uuid')
            ->with(['event', 'transactionOrders.eventTicket', 'affiliateConversion', 'organization'])
            ->orderBy('uuid')
            ->chunkById(200, function ($transactions) use (&$byEvent) {
                foreach ($transactions as $tx) {
                    /** @var Transaction $tx */
                    $eventUuid = (string) $tx->event_uuid;
                    if ($eventUuid === '') {
                        continue;
                    }

                    if (! isset($byEvent[$eventUuid])) {
                        $byEvent[$eventUuid] = ['total_sales' => 0.0, 'order_count' => 0];
                    }

                    $byEvent[$eventUuid]['total_sales'] += $this->merchantSalesForTransaction($tx);
                    $byEvent[$eventUuid]['order_count']++;
                }
            }, 'uuid');

        uasort($byEvent, fn (array $a, array $b) => $b['total_sales'] <=> $a['total_sales']);

        return array_slice($byEvent, 0, $limit, true);
    }

    /**
     * @return array<string, array{total_sales: float, total_quantity: int}>
     */
    private function aggregateNetSellingByTicketType(Builder|Relation $query, int $limit = 10): array
    {
        $byTicket = [];

        (clone $this->transactionQueryBuilder($query))
            ->where('payment_status', Transaction::PAYMENT_STATUS['PAID'])
            ->with(['event', 'transactionOrders.eventTicket', 'affiliateConversion', 'organization'])
            ->orderBy('uuid')
            ->chunkById(200, function ($transactions) use (&$byTicket) {
                foreach ($transactions as $tx) {
                    /** @var Transaction $tx */
                    if ($tx->transactionOrders->isEmpty() || ! $tx->event) {
                        continue;
                    }

                    $tx->loadMissing('organization');
                    $rate = $this->accountingBalanceService->commissionRate($tx->organization);
                    $ordersSum = (float) $tx->transactionOrders->sum('total_amount');

                    foreach ($tx->transactionOrders as $order) {
                        $ticketUuid = (string) $order->event_ticket_uuid;
                        if ($ticketUuid === '') {
                            continue;
                        }

                        $orderTotal = (float) $order->total_amount;
                        $affiliateCommission = $ordersSum > 0
                            ? ($orderTotal / $ordersSum) * (float) ($tx->affiliateConversion?->commission_amount ?? 0.0)
                            : 0.0;

                        $lineAmounts = TicketPurchasePricingService::lineAmountsForPaidOrder(
                            $tx,
                            $order,
                            $rate,
                            $affiliateCommission,
                        );

                        if (! isset($byTicket[$ticketUuid])) {
                            $byTicket[$ticketUuid] = ['total_sales' => 0.0, 'total_quantity' => 0];
                        }

                        $byTicket[$ticketUuid]['total_sales'] += TicketPurchasePricingService::lineMerchantSalesAmount($lineAmounts);
                        $byTicket[$ticketUuid]['total_quantity'] += $quantity;
                    }
                }
            }, 'uuid');

        foreach ($byTicket as $uuid => $row) {
            $byTicket[$uuid]['total_sales'] = round($row['total_sales'], 2);
        }

        uasort($byTicket, fn (array $a, array $b) => $b['total_sales'] <=> $a['total_sales']);

        return array_slice($byTicket, 0, $limit, true);
    }

    /**
     * Display analytics stats.
     * @return JsonResponse
     */
    public function stats(): JsonResponse
    {
        $paidBase = Transaction::byOrganization();

        $totalSales = $this->sumPaidMerchantSales(clone $paidBase);
        $thisMonthTransactions = $this->sumPaidMerchantSales(
            Transaction::byOrganization()
                ->whereMonth('created_at', now()->month),
        );

        $weeklySales = $this->sumPaidMerchantSales(
            Transaction::byOrganization()
                ->where('created_at', '>=', now()->subDays(7)),
        );

        $dailySales = $this->sumPaidMerchantSales(
            Transaction::byOrganization()
                ->whereDate('created_at', now()->toDateString()),
        );

        // Event Analytics
        $totalEvents = Event::byOrganization()->count();
        $activeEvents = Event::byOrganization()->where('status', GeneralConstants::EVENT_STATUSES['PUBLISHED'])
            ->whereNull('cancelled_at')
            ->whereNull('completed_at')
            ->count();
        $featuredEvents = Event::byOrganization()->where('is_featured', true)->count();
        $pastEvents = Event::byOrganization()->whereNotNull('completed_at')->count();

        // Transaction Analytics
        $totalTransactions = Transaction::byOrganization()->count();

        return response()->json([
            'success' => true,
            'message' => 'Organizer analytics stats',
            'data' => [
                'transactions' => $totalSales,
                'this_month_transactions' => $thisMonthTransactions,
                'weekly_sales' => $weeklySales,
                'daily_sales' => $dailySales,
                'total_events' => $totalEvents,
                'active_events' => $activeEvents,
                'featured_events' => $featuredEvents,
                'past_events' => $pastEvents,
                'total_transactions' => $totalTransactions,
            ],
        ]);
    }

    /**
     * Get sales analytics data.
     * @param Request $request
     * @return JsonResponse
     */
    public function sales(Request $request): JsonResponse
    {
        $orgScope = Transaction::byOrganization();

        $totalSales = $this->sumPaidMerchantSales(clone $orgScope);

        $thisMonthSales = $this->sumPaidMerchantSales(
            Transaction::byOrganization()
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year),
        );

        $thisWeekSales = $this->sumPaidMerchantSales(
            Transaction::byOrganization()
                ->where('created_at', '>=', now()->startOfWeek()),
        );

        $todaySales = $this->sumPaidMerchantSales(
            Transaction::byOrganization()
                ->whereDate('created_at', now()->toDateString()),
        );

        $monthlyTotals = $this->aggregateNetSellingByMonth(clone $orgScope);
        $monthlyTrend = collect($monthlyTotals)
            ->sortKeys()
            ->map(function (float $total, string $monthKey) {
                $date = Carbon::createFromFormat('Y-m', $monthKey);

                return [
                    'month' => $date->format('F Y'),
                    'year_month' => $monthKey,
                    'total' => $total,
                ];
            })
            ->values();

        $topSellingEvents = collect($this->aggregateNetSellingByEvent(clone $orgScope))
            ->map(function (array $row, string $eventUuid) {
                $event = Event::with(['organization', 'creator'])->find($eventUuid);

                return [
                    'event_uuid' => $eventUuid,
                    'event_name' => $event ? $event->event_name : 'Unknown Event',
                    'organizer' => $event && $event->organization
                        ? $event->organization->name
                        : ($event && $event->creator
                            ? $event->creator->full_name ?? $event->creator->first_name ?? 'Admin User'
                            : 'Unknown'),
                    'total_sales' => round($row['total_sales'], 2),
                    'order_count' => $row['order_count'],
                ];
            })
            ->values();

        $salesByTicketType = collect($this->aggregateNetSellingByTicketType(clone $orgScope))
            ->map(function (array $row, string $ticketUuid) {
                $eventTicket = \App\Models\EventTicket::with('event')->find($ticketUuid);

                return [
                    'event_ticket_uuid' => $ticketUuid,
                    'ticket_name' => $eventTicket ? $eventTicket->name : 'Unknown Ticket',
                    'event_name' => $eventTicket && $eventTicket->event ? $eventTicket->event->event_name : 'Unknown Event',
                    'total_sales' => $row['total_sales'],
                    'total_quantity' => $row['total_quantity'],
                ];
            })
            ->values();

        return response()->json([
            'success' => true,
            'message' => 'Sales analytics data',
            'data' => [
                'total_sales' => $totalSales,
                'this_month_sales' => $thisMonthSales,
                'this_week_sales' => $thisWeekSales,
                'today_sales' => $todaySales,
                'monthly_trend' => $monthlyTrend,
                'top_selling_events' => $topSellingEvents,
                'sales_by_ticket_type' => $salesByTicketType,
            ],
        ]);
    }

    /**
     * Get event analytics data.
     * @param Request $request
     * @return JsonResponse
     */
    public function events(Request $request): JsonResponse
    {
        // Calculate KPIs
        $totalEvents = Event::byOrganization()->count();
        $publishedEvents = Event::byOrganization()->where('status', GeneralConstants::EVENT_STATUSES['PUBLISHED'])->count();
        $pendingEvents = Event::byOrganization()->where('status', GeneralConstants::EVENT_STATUSES['PENDING'])->count();
        $featuredEvents = Event::byOrganization()->where('is_featured', true)->count();

        // Event Status Breakdown
        $approvedEvents = Event::byOrganization()->where('status', GeneralConstants::EVENT_STATUSES['PUBLISHED'])->count();

        // Events with sales (have at least one PAID transaction)
        $eventsWithSales = Event::byOrganization()->whereHas('transactions', function ($query) {
            $query->where('payment_status', Transaction::PAYMENT_STATUS['PAID']);
        })->count();

        $activeEvents = Event::byOrganization()->where('status', GeneralConstants::EVENT_STATUSES['PUBLISHED'])
            ->whereNull('cancelled_at')
            ->whereNull('completed_at')
            ->count();

        $pendingApproval = Event::byOrganization()->where('status', GeneralConstants::EVENT_STATUSES['PENDING'])->count();

        // Top Performing Events (Top 5 by total revenue from transactions)
        $topPerformingEvents = Event::with(['organization', 'creator'])
            ->byOrganization()
            ->get()
            ->map(function ($event) {
                $transactions = $event->transactions()
                    ->byOrganization()
                    ->where('payment_status', Transaction::PAYMENT_STATUS['PAID'])
                    ->get();

                $totalRevenue = $transactions->sum(fn (Transaction $tx) => $tx->merchantRevenueAmount());
                $orderCount = $transactions->count();

                return [
                    'event' => $event,
                    'total_revenue' => $totalRevenue,
                    'order_count' => $orderCount,
                ];
            })
            ->sortByDesc('total_revenue')
            ->take(5)
            ->map(function ($item) {
                $event = $item['event'];
                $organizer = $event->organization
                    ? $event->organization->name
                    : ($event->creator
                        ? ($event->creator->full_name ?? $event->creator->first_name ?? 'Admin User')
                        : 'Unknown');

                $status = $event->status === GeneralConstants::EVENT_STATUSES['PUBLISHED'] ? 'published' : '';

                return [
                    'event_uuid' => $event->uuid,
                    'event_name' => $event->event_name,
                    'organizer' => $organizer,
                    'status' => $status,
                    'is_featured' => $event->is_featured,
                    'total_revenue' => (float) $item['total_revenue'],
                    'order_count' => $item['order_count'],
                ];
            })
            ->values();

        // All Events with their metrics
        $allEvents = Event::with(['organization', 'creator', 'eventTickets', 'transactions'])
            ->byOrganization()
            ->get()
            ->map(function ($event) {
                $organizer = $event->organization
                    ? $event->organization->name
                    : ($event->creator
                        ? ($event->creator->full_name ?? $event->creator->first_name ?? 'Admin User')
                        : 'Unknown');

                // Get total revenue from PAID transactions
                $totalRevenue = $this->sumPaidMerchantSales(
                    $event->transactions()->byOrganization(),
                );

                // Get order count from PAID transactions
                $orderCount = $event->transactions()
                    ->byOrganization()
                    ->where('payment_status', Transaction::PAYMENT_STATUS['PAID'])
                    ->count();

                // Get ticket types count
                $ticketTypesCount = $event->eventTickets()->count();

                return [
                    'event_uuid' => $event->uuid,
                    'event_name' => $event->event_name,
                    'organizer' => $organizer,
                    'status' => $event->status,
                    'is_featured' => $event->is_featured,
                    'is_published' => $event->status === GeneralConstants::EVENT_STATUSES['PUBLISHED'],
                    'is_approved' => $event->status === GeneralConstants::EVENT_STATUSES['APPROVED'],
                    'total_revenue' => (float) $totalRevenue,
                    'order_count' => $orderCount,
                    'ticket_types_count' => $ticketTypesCount,
                ];
            })
            ->sortByDesc('total_revenue')
            ->values();

        return response()->json([
            'success' => true,
            'message' => 'Event analytics data',
            'data' => [
                'total_events' => $totalEvents,
                'published_events' => $publishedEvents,
                'pending_events' => $pendingEvents,
                'featured_events' => $featuredEvents,
                'status_breakdown' => [
                    'approved_events' => $approvedEvents,
                    'events_with_sales' => $eventsWithSales,
                    'active_events' => $activeEvents,
                    'pending_approval' => $pendingApproval,
                ],
                'top_performing_events' => $topPerformingEvents,
                'all_events' => $allEvents,
            ],
        ]);
    }

    /**
     * Revenue series scoped to the authenticated organizer's organization.
     */
    public function transactionRevenueSeries(TransactionRevenueSeriesRequest $request): JsonResponse
    {
        $organizationUuid = auth('admin')->user()?->organization_uuid;
        if (!$organizationUuid) {
            return response()->json([
                'success' => false,
                'message' => 'Organization context required.',
            ], 422);
        }

        $validated = $request->validated();

        $data = $this->analyticsRepository->getTransactionRevenueSeries(
            $validated['granularity'],
            $organizationUuid,
            isset($validated['start_date']) ? (string) $validated['start_date'] : null,
            isset($validated['end_date']) ? (string) $validated['end_date'] : null,
            merchantRevenueOnly: true,
        );

        return response()->json([
            'success' => true,
            'message' => 'Transaction revenue series',
            'data' => $data,
        ]);
    }

    public function exportTransactionRevenueSeries(TransactionRevenueSeriesRequest $request): \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
    {
        $organizationUuid = auth('admin')->user()?->organization_uuid;
        if (! $organizationUuid) {
            return response()->json([
                'success' => false,
                'message' => 'Organization context required.',
            ], 422);
        }

        $validated = $request->validated();

        $csvContent = $this->analyticsRepository->exportTransactionRevenueSeriesCsv(
            $validated['granularity'],
            $organizationUuid,
            isset($validated['start_date']) ? (string) $validated['start_date'] : null,
            isset($validated['end_date']) ? (string) $validated['end_date'] : null,
            includeAdminOnlyColumns: false,
        );

        $fileName = 'transactions_'.$validated['granularity'].'_'.now()->format('Y-m-d_His').'.csv';

        return response($csvContent, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$fileName.'"',
            'Access-Control-Expose-Headers' => 'Content-Disposition, Content-Type',
            'Cache-Control' => 'no-cache, private',
            'Pragma' => 'no-cache',
        ]);
    }

    /**
     * Successful vs failed counts scoped to the authenticated organizer's organization.
     */
    public function successfulFailedTransactionCountsSeries(TransactionRevenueSeriesRequest $request): JsonResponse
    {
        $organizationUuid = auth('admin')->user()?->organization_uuid;
        if (!$organizationUuid) {
            return response()->json([
                'success' => false,
                'message' => 'Organization context required.',
            ], 422);
        }

        $validated = $request->validated();

        $data = $this->analyticsRepository->getSuccessfulFailedTransactionCountsSeries(
            $validated['granularity'],
            $organizationUuid,
            isset($validated['start_date']) ? (string) $validated['start_date'] : null,
            isset($validated['end_date']) ? (string) $validated['end_date'] : null,
        );

        return response()->json([
            'success' => true,
            'message' => 'Successful vs failed transaction counts',
            'data' => $data,
        ]);
    }

    /**
     * Revenue per event series scoped to the authenticated organizer's organization only.
     */
    public function revenuePerEventSeries(TransactionRevenueSeriesRequest $request): JsonResponse
    {
        $organizationUuid = auth('admin')->user()?->organization_uuid;
        if (!$organizationUuid) {
            return response()->json([
                'success' => false,
                'message' => 'Organization context required.',
            ], 422);
        }

        $validated = $request->validated();

        $data = $this->analyticsRepository->getRevenuePerEventSeries(
            $validated['granularity'],
            $organizationUuid,
            isset($validated['start_date']) ? (string) $validated['start_date'] : null,
            isset($validated['end_date']) ? (string) $validated['end_date'] : null,
            merchantRevenueOnly: true,
        );

        return response()->json([
            'success' => true,
            'message' => 'Revenue per event series',
            'data' => $data,
        ]);
    }
}
