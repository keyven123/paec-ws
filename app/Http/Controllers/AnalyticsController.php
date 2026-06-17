<?php

namespace App\Http\Controllers;

use App\Constants\GeneralConstants;
use App\Http\Repositories\AnalyticsRepository;
use App\Http\Requests\Analytics\AnalyticsPieRequest;
use App\Http\Requests\Analytics\CancelledCheckoutRequest;
use App\Http\Requests\Analytics\SalesReportExportRequest;
use App\Http\Requests\Analytics\TransactionRevenueSeriesRequest;
use App\Models\Event;
use App\Models\EventTicket;
use App\Models\Transaction;
use App\Models\TransactionOrder;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use Carbon\Carbon;

class AnalyticsController extends Controller
{
    public function __construct(protected AnalyticsRepository $analyticsRepository)
    {
    }

    /**
     * Display analytics stats.
     * @param Request $request
     * @return JsonResponse
     */
    public function stats(Request $request): JsonResponse
    {
        // Sales Analytics
        $totalSales = Transaction::get()->sum('total_amount');
        $totalRevenue = Transaction::where('payment_status', Transaction::PAYMENT_STATUS['PAID'])->sum('total_amount');
        $thisMonthTransactions = Transaction::whereMonth('created_at', now()->month)
            ->where('payment_status', Transaction::PAYMENT_STATUS['PAID'])
            ->get()
            ->sum('total_amount');

        // Weekly sales (last 7 days)
        $weeklySales = Transaction::where('created_at', '>=', now()->subDays(7))
            ->where('payment_status', Transaction::PAYMENT_STATUS['PAID'])
            ->get()
            ->sum('total_amount');

        // Daily sales (today)
        $dailySales = Transaction::whereDate('created_at', now()->toDateString())
            ->where('payment_status', Transaction::PAYMENT_STATUS['PAID'])
            ->get()
            ->sum('total_amount');

        // Event Analytics
        $totalEvents = Event::get()->count();
        $activeEvents = Event::where('status', GeneralConstants::EVENT_STATUSES['PUBLISHED'])
            ->whereNull('cancelled_at')
            ->whereNull('completed_at')
            ->count();
        $featuredEvents = Event::where('is_featured', true)->count();
        $pastEvents = Event::whereNotNull('completed_at')->count();

        // User Analytics
        $totalUsers = User::get()->count();

        return response()->json([
            'success' => true,
            'message' => 'Analytics stats',
            'data' => [
                'transactions' => $totalSales,
                'total_revenue' => (float) $totalRevenue,
                'this_month_transactions' => $thisMonthTransactions,
                'weekly_sales' => $weeklySales,
                'daily_sales' => $dailySales,
                'total_events' => $totalEvents,
                'active_events' => $activeEvents,
                'featured_events' => $featuredEvents,
                'past_events' => $pastEvents,
                'total_users' => $totalUsers,
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
        // Calculate KPIs
        $totalSales = Transaction::where('payment_status', Transaction::PAYMENT_STATUS['PAID'])
            ->sum('total_amount');

        $thisMonthSales = Transaction::where('payment_status', Transaction::PAYMENT_STATUS['PAID'])
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->sum('total_amount');

        $thisWeekSales = Transaction::where('payment_status', Transaction::PAYMENT_STATUS['PAID'])
            ->where('created_at', '>=', now()->startOfWeek())
            ->sum('total_amount');

        $todaySales = Transaction::where('payment_status', Transaction::PAYMENT_STATUS['PAID'])
            ->whereDate('created_at', now()->toDateString())
            ->sum('total_amount');

        // Monthly Sales Trend (last 6 months)
        $monthlyTrend = Transaction::where('payment_status', Transaction::PAYMENT_STATUS['PAID'])
            ->select(
                DB::raw('DATE_FORMAT(created_at, "%Y-%m") as month'),
                DB::raw('SUM(total_amount) as total')
            )
            ->where('created_at', '>=', now()->subMonths(6))
            ->groupBy(DB::raw('DATE_FORMAT(created_at, "%Y-%m")'))
            ->orderBy('month', 'desc')
            ->get()
            ->map(function ($item) {
                $date = Carbon::createFromFormat('Y-m', $item->month);
                return [
                    'month' => $date->format('F Y'),
                    'year_month' => $item->month,
                    'total' => (float) $item->total,
                ];
            })
            ->reverse()
            ->values();

        // Top Selling Events (Top 10)
        $topSellingEvents = Transaction::where('payment_status', Transaction::PAYMENT_STATUS['PAID'])
            ->select(
                'event_uuid',
                DB::raw('SUM(total_amount) as total_sales'),
                DB::raw('COUNT(*) as order_count')
            )
            ->whereNotNull('event_uuid')
            ->groupBy('event_uuid')
            ->orderByDesc('total_sales')
            ->limit(10)
            ->get()
            ->map(function ($item) {
                $event = Event::with(['organization', 'creator'])->find($item->event_uuid);
                return [
                    'event_uuid' => $item->event_uuid,
                    'event_name' => $event ? $event->event_name : 'Unknown Event',
                    'organizer' => $event && $event->organization
                        ? $event->organization->name
                        : ($event && $event->creator
                            ? $event->creator->full_name ?? $event->creator->first_name ?? 'Admin User'
                            : 'Unknown'),
                    'total_sales' => (float) $item->total_sales,
                    'order_count' => $item->order_count,
                ];
            });

        // Sales by Ticket Type (Top 10)
        $salesByTicketType = TransactionOrder::select(
            'event_ticket_uuid',
            DB::raw('SUM(quantity * price) as total_sales'),
            DB::raw('SUM(quantity) as total_quantity')
        )
            ->whereHas('transaction', function ($query) {
                $query->where('payment_status', Transaction::PAYMENT_STATUS['PAID']);
            })
            ->groupBy('event_ticket_uuid')
            ->orderByDesc('total_sales')
            ->limit(10)
            ->get()
            ->map(function ($item) {
                $eventTicket = EventTicket::with([
                    'event' => function ($q) {
                        $q->whereIn('status', [
                            GeneralConstants::TICKET_STATUSES['ACTIVE'],
                            GeneralConstants::TICKET_STATUSES['USED']
                        ]);
                    }
                ])->find($item->event_ticket_uuid);
                return [
                    'event_ticket_uuid' => $item->event_ticket_uuid,
                    'ticket_name' => $eventTicket ? $eventTicket->name : 'Unknown Ticket',
                    'event_name' => $eventTicket && $eventTicket->event ? $eventTicket->event->event_name : 'Unknown Event',
                    'total_sales' => (float) $item->total_sales,
                    'total_quantity' => $item->total_quantity,
                ];
            });

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
        $totalEvents = Event::count();
        $publishedEvents = Event::where('status', GeneralConstants::EVENT_STATUSES['PUBLISHED'])->count();
        $pendingEvents = Event::where('status', GeneralConstants::EVENT_STATUSES['PENDING'])->count();
        $featuredEvents = Event::where('is_featured', true)->count();

        // Event Status Breakdown
        $approvedEvents = Event::where('status', GeneralConstants::EVENT_STATUSES['APPROVED'])->count();

        // Events with sales (have at least one PAID transaction)
        $eventsWithSales = Event::whereHas('transactions', function ($query) {
            $query->where('payment_status', Transaction::PAYMENT_STATUS['PAID']);
        })->count();

        $activeEvents = Event::where('status', GeneralConstants::EVENT_STATUSES['PUBLISHED'])
            ->whereNull('cancelled_at')
            ->whereNull('completed_at')
            ->count();

        $pendingApproval = Event::where('status', GeneralConstants::EVENT_STATUSES['PENDING'])->count();

        // Top Performing Events (Top 5 by total revenue from transactions)
        $topPerformingEvents = Event::with(['organization', 'creator'])
            ->get()
            ->map(function ($event) {
                $transactions = $event->transactions()
                    ->where('payment_status', Transaction::PAYMENT_STATUS['PAID'])
                    ->get();

                $totalRevenue = $transactions->sum('total_amount');
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
            ->get()
            ->map(function ($event) {
                $organizer = $event->organization
                    ? $event->organization->name
                    : ($event->creator
                        ? ($event->creator->full_name ?? $event->creator->first_name ?? 'Admin User')
                        : 'Unknown');

                // Get total revenue from PAID transactions
                $totalRevenue = $event->transactions()
                    ->where('payment_status', Transaction::PAYMENT_STATUS['PAID'])
                    ->sum('total_amount');

                // Get order count from PAID transactions
                $orderCount = $event->transactions()
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
                    'published_events' => $publishedEvents,
                    'events_with_sales' => $eventsWithSales,
                    'active_events' => $activeEvents,
                    'pending_approval' => $pendingApproval,
                ],
                'top_performing_events' => $topPerformingEvents,
                'all_events' => $allEvents,
            ],
        ]);
    }

    public function salesReport(SalesReportExportRequest $request): \Illuminate\Http\Response
    {
        $payload = $request->validated();

        $csvContent = $this->analyticsRepository->exportSalesReport($payload['start_date'], $payload['end_date']);

        $cleanEventName = preg_replace('/[^a-zA-Z0-9_-]/', '_', 'Sales Report ' . date('Y-m-d', strtotime($payload['start_date'])) . ' to ' . date('Y-m-d', strtotime($payload['end_date'])));
        $fileName =  $cleanEventName . '.csv';

        return response($csvContent, 200, [
            'Content-Type'              => 'text/csv; charset=utf-8',
            'Content-Disposition'       => 'attachment; filename="' . $fileName . '"',
            'Access-Control-Expose-Headers' => 'Content-Disposition, Content-Type',
            'Cache-Control'             => 'no-cache, private',
            'Pragma'                    => 'no-cache',
        ]);
    }

    /**
     * Time series of paid transaction totals for charting.
     */
    public function transactionRevenueSeries(TransactionRevenueSeriesRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $data = $this->analyticsRepository->getTransactionRevenueSeries(
            $validated['granularity'],
            $validated['organization_uuid'] ?? null,
            isset($validated['start_date']) ? (string) $validated['start_date'] : null,
            isset($validated['end_date']) ? (string) $validated['end_date'] : null,
        );

        return response()->json([
            'success' => true,
            'message' => 'Transaction revenue series',
            'data' => $data,
        ]);
    }

    public function exportTransactionRevenueSeries(TransactionRevenueSeriesRequest $request): \Illuminate\Http\Response
    {
        $validated = $request->validated();

        $csvContent = $this->analyticsRepository->exportTransactionRevenueSeriesCsv(
            $validated['granularity'],
            $validated['organization_uuid'] ?? null,
            isset($validated['start_date']) ? (string) $validated['start_date'] : null,
            isset($validated['end_date']) ? (string) $validated['end_date'] : null,
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
     * Time series: count of successful (paid) vs failed (failed + cancelled payment status) transactions.
     */
    public function successfulFailedTransactionCountsSeries(TransactionRevenueSeriesRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $data = $this->analyticsRepository->getSuccessfulFailedTransactionCountsSeries(
            $validated['granularity'],
            $validated['organization_uuid'] ?? null,
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
     * Time series: number of new user accounts created per bucket (x: users.created_at bucket, y: count).
     */
    public function userSignupsSeries(\App\Http\Requests\Analytics\UserSignupSeriesRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $data = $this->analyticsRepository->getUserSignupsSeries(
            $validated['granularity'],
            isset($validated['start_date']) ? (string) $validated['start_date'] : null,
            isset($validated['end_date']) ? (string) $validated['end_date'] : null,
        );

        return response()->json([
            'success' => true,
            'message' => 'User signups series',
            'data' => $data,
        ]);
    }

    /**
     * Stacked revenue by event per time bucket (admin analytics).
     */
    public function revenuePerEventSeries(TransactionRevenueSeriesRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $data = $this->analyticsRepository->getRevenuePerEventSeries(
            $validated['granularity'],
            $validated['organization_uuid'] ?? null,
            isset($validated['start_date']) ? (string) $validated['start_date'] : null,
            isset($validated['end_date']) ? (string) $validated['end_date'] : null,
        );

        return response()->json([
            'success' => true,
            'message' => 'Revenue per event series',
            'data' => $data,
        ]);
    }

    /**
     * Pie chart: total paid revenue per event.
     */
    public function revenueByEventPie(AnalyticsPieRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $data = $this->analyticsRepository->getRevenueByEventTotals(
            $validated['organization_uuid'] ?? null
        );

        return response()->json([
            'success' => true,
            'message' => 'Revenue by event totals',
            'data' => [
                'items' => $data,
            ],
        ]);
    }

    /**
     * Pie chart: customers split into New vs Repeat based on paid transaction count.
     */
    public function customerTypePie(AnalyticsPieRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $data = $this->analyticsRepository->getNewVsRepeatCustomerCounts(
            $validated['organization_uuid'] ?? null
        );

        return response()->json([
            'success' => true,
            'message' => 'Customer type totals',
            'data' => $data,
        ]);
    }

    /**
     * Pie chart: overall successful vs failed event transactions.
     */
    public function successfulFailedTransactionPie(AnalyticsPieRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $data = $this->analyticsRepository->getSuccessfulFailedTransactionTotals(
            $validated['organization_uuid'] ?? null,
        );

        return response()->json([
            'success' => true,
            'message' => 'Successful vs failed transaction totals',
            'data' => $data,
        ]);
    }

    public function cancelledCheckouts(CancelledCheckoutRequest $request): \Illuminate\Http\Response
    {
        $payload = $request->validated();

        $csvContent = $this->analyticsRepository->cancelledCheckouts($payload['start_date'], $payload['end_date']);

        $cleanEventName = preg_replace('/[^a-zA-Z0-9_-]/', '_', 'Cancelled Checkouts Report ' . date('Y-m-d', strtotime($payload['start_date'])) . ' to ' . date('Y-m-d', strtotime($payload['end_date'])));
        $fileName =  $cleanEventName . '.csv';

        return response($csvContent, 200, [
            'Content-Type'              => 'text/csv; charset=utf-8',
            'Content-Disposition'       => 'attachment; filename="' . $fileName . '"',
            'Access-Control-Expose-Headers' => 'Content-Disposition, Content-Type',
            'Cache-Control'             => 'no-cache, private',
            'Pragma'                    => 'no-cache',
        ]);
    }
}
