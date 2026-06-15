<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Ticket;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Request;

class AdminDashboardController extends Controller
{
    /**
     * Get recent activities for the admin dashboard.
     * @param Request $request
     * @return JsonResponse
     */
    public function recentActivities(Request $request): JsonResponse
    {
        // Get 5 most recent purchases (paid transactions)
        $purchaseActivities = Transaction::with(['user', 'event'])
            ->where('payment_status', Transaction::PAYMENT_STATUS['PAID'])
            ->latest()
            ->limit(5)
            ->get()
            ->map(function ($purchase) {
                $eventName = $purchase->event ? $purchase->event->event_name : 'an event';
                $userName = $purchase->user ? ($purchase->user->full_name ?? $purchase->user->name ?? 'User') : 'User';
                $orders = $purchase->transactionOrders()->get()->map(function ($ticket) {
                    return $ticket->eventTicket->name . ' x' . $ticket->quantity;
                })->implode(', ');
                if ($purchase->payment_order_id) {
                    return [
                        'type' => 'purchase',
                        'message' => "$userName purchased tickets for $eventName: $orders",
                        'timestamp' => $purchase->created_at,
                        'data' => $purchase,
                    ];
                }

                if (!$purchase->payment_order_id) {
                    $transaction = Transaction::find($purchase->uuid)->first();
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
            ->latest()
            ->limit(3)
            ->get()
            ->map(function ($event) {
                return [
                    'type' => 'event',
                    'message' => "New event created: {$event->event_name}",
                    'timestamp' => $event->created_at,
                    'data' => $event,
                ];
            });

        // Get 5 most recent users to surface fresh registrations
        $userActivities = User::latest()
            ->limit(5)
            ->get()
            ->map(function ($user) {
                return [
                    'type' => 'user',
                    'message' => "New user registered: {$user->full_name}",
                    'timestamp' => $user->created_at,
                    'data' => $user,
                ];
            });

        // Merge and sort all activities by timestamp desc, limit 15
        $activities = collect()
            ->merge($purchaseActivities)
            ->merge($eventActivities)
            ->merge($userActivities)
            ->sortByDesc('timestamp')
            ->take(15)
            ->values();

        return response()->json([
            'success' => true,
            'message' => 'Recent activity feed',
            'activities' => $activities,
        ]);
    }

    /**
     * Get main dashboard statistics (total events, users, tickets sold, revenue)
     * @param Request $request
     * @return JsonResponse
     */
    public function dashboardStats(Request $request): JsonResponse
    {
        $totalEvents = Event::count();
        $totalUsers = User::count();
        $ticketsSold = Ticket::whereHas('transaction', function ($query) {
            $query->where('payment_status', Transaction::PAYMENT_STATUS['PAID']);
            $query->whereNotNull('payment_provider');
        })->count();
        $totalRevenue = Transaction::where('payment_status', Transaction::PAYMENT_STATUS['PAID'])->sum('total_amount');

        return response()->json([
            'success' => true,
            'message' => 'Dashboard statistics',
            'data' => [
                'total_events' => $totalEvents,
                'total_users' => $totalUsers,
                'tickets_sold' => $ticketsSold,
                'total_revenue' => (float) $totalRevenue,
            ],
        ]);
    }
}
