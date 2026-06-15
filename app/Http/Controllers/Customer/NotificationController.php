<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlatformNotificationResource;
use App\Models\PlatformNotification;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = auth('api')->user();

        $notifications = PlatformNotification::where('notifiable_type', User::class)
            ->where('notifiable_uuid', $user->uuid)
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json([
            'data' => PlatformNotificationResource::collection($notifications->items()),
            'meta' => [
                'current_page'  => $notifications->currentPage(),
                'last_page'     => $notifications->lastPage(),
                'unread_count'  => PlatformNotification::where('notifiable_type', User::class)
                    ->where('notifiable_uuid', $user->uuid)
                    ->whereNull('read_at')
                    ->count(),
            ],
        ]);
    }

    public function unreadCount(): JsonResponse
    {
        /** @var User $user */
        $user = auth('api')->user();

        $count = PlatformNotification::where('notifiable_type', User::class)
            ->where('notifiable_uuid', $user->uuid)
            ->whereNull('read_at')
            ->count();

        return response()->json(['unread_count' => $count]);
    }

    public function markRead(string $uuid): JsonResponse
    {
        /** @var User $user */
        $user = auth('api')->user();

        $notification = PlatformNotification::where('uuid', $uuid)
            ->where('notifiable_type', User::class)
            ->where('notifiable_uuid', $user->uuid)
            ->firstOrFail();

        $notification->markRead();

        return response()->json(['success' => true]);
    }

    public function markAllRead(): JsonResponse
    {
        /** @var User $user */
        $user = auth('api')->user();

        PlatformNotification::where('notifiable_type', User::class)
            ->where('notifiable_uuid', $user->uuid)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['success' => true]);
    }
}
