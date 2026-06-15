<?php

namespace App\Http\Controllers\Organizer;

use App\Constants\GeneralConstants;
use App\Http\Controllers\Controller;
use App\Http\Resources\EventResource;
use App\Models\Event;
use App\Models\Ticket;
use App\Models\Transaction;
use App\Services\TicketPurchasePricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Request;

class OrganizerDashboardController extends Controller
{
    /**
     * Get recent activities for the organizer dashboard.
     * @param Request $request
     * @return JsonResponse
     */
    public function recentActivities(Request $request): JsonResponse
    {
        // Get 5 most recent purchases (paid transactions)
        $purchaseActivities = Transaction::with(['user', 'event'])
            ->byOrganization()
            ->where('payment_status', Transaction::PAYMENT_STATUS['PAID'])
            ->latest()
            ->limit(5)
            ->get()
            ->map(function ($purchase) {
                $userName = $purchase->user ? ($purchase->user->full_name ?? $purchase->user->name ?? 'User') : 'User';
                $eventName = $purchase->event ? $purchase->event->event_name : 'an event';
                if ($purchase->payment_order_id) {
                    return [
                        'type' => 'purchase',
                        'message' => "$userName purchased tickets for $eventName with total amount of {$purchase->total_amount}",
                        'timestamp' => $purchase->created_at,
                        'data' => $purchase,
                    ];
                }

                if (!$purchase->payment_order_id) {
                    return [
                        'type' => 'purchase',
                        'message' => $transaction->createdBy->full_name ?? 'System' . " manually added ticket to user {$userName} for {$eventName}",
                        'timestamp' => $purchase->created_at,
                        'data' => $purchase,
                    ];
                }
            });

        // Get 3 most recent events
        $eventActivities = Event::with('creator')
            ->byOrganization()
            ->latest()
            ->limit(5)
            ->get()
            ->map(function ($event) {
                return [
                    'type' => 'event',
                    'message' => "New event created: {$event->event_name}",
                    'timestamp' => $event->created_at,
                    'data' => $event,
                ];
            });

        // Merge and sort all activities by timestamp desc, limit 15
        $activities = collect()
            ->merge($purchaseActivities)
            ->merge($eventActivities)
            ->sortByDesc('timestamp')
            ->take(10)
            ->values();

        return response()->json([
            'success' => true,
            'message' => 'Recent activity feed',
            'activities' => $activities,
        ]);
    }

    public function upcomingEvents(Request $request): JsonResponse
    {
        $upcomingEvents = Event::byOrganization()
            ->with('portraitImage', 'featuredImage', 'schedules', 'venue')
            ->published()
            ->whereHas('schedules', function ($query) {
                $query->where('date_from', '>=', now()->toDateString());
            })
            ->limit(4)
            ->get();

        return EventResource::collection($upcomingEvents)->response();
    }

    /**
     * Get main dashboard statistics (total events, users, tickets sold, revenue)
     * @param Request $request
     * @return JsonResponse
     */
    public function dashboardStats(Request $request): JsonResponse
    {
        $totalEvents = Event::byOrganization()->count();
        $activeEvents = Event::byOrganization()
            ->where('status', GeneralConstants::EVENT_STATUSES['PUBLISHED'])
            ->count();
        $ticketsSold = Ticket::byOrganization()
            ->whereHas('transaction', function ($query) {
                $query->where('payment_status', Transaction::PAYMENT_STATUS['PAID']);
                $query->whereNotNull('payment_provider');
            })->count();
        $totalRevenue = TicketPurchasePricingService::sumNetSellingForPaidTransactions(
            Transaction::byOrganization(),
        );

        return response()->json([
            'success' => true,
            'message' => 'Dashboard statistics',
            'data' => [
                'total_events' => $totalEvents,
                'active_events' => $activeEvents,
                'tickets_sold' => $ticketsSold,
                'total_revenue' => (float) $totalRevenue,
            ],
        ]);
    }
}
