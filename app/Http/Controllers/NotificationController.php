<?php

namespace App\Http\Controllers;

use App\Http\Resources\PlatformNotificationResource;
use App\Models\AdminUser;
use App\Models\PlatformNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var AdminUser $admin */
        $admin = auth('admin')->user();

        $notifications = PlatformNotification::where('notifiable_type', AdminUser::class)
            ->where('notifiable_uuid', $admin->uuid)
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json([
            'data' => PlatformNotificationResource::collection($notifications->items()),
            'meta' => [
                'current_page' => $notifications->currentPage(),
                'last_page'    => $notifications->lastPage(),
                'unread_count' => PlatformNotification::where('notifiable_type', AdminUser::class)
                    ->where('notifiable_uuid', $admin->uuid)
                    ->whereNull('read_at')
                    ->count(),
            ],
        ]);
    }

    public function unreadCount(): JsonResponse
    {
        /** @var AdminUser $admin */
        $admin = auth('admin')->user();

        $count = PlatformNotification::where('notifiable_type', AdminUser::class)
            ->where('notifiable_uuid', $admin->uuid)
            ->whereNull('read_at')
            ->count();

        return response()->json(['unread_count' => $count]);
    }

    public function markRead(string $uuid): JsonResponse
    {
        /** @var AdminUser $admin */
        $admin = auth('admin')->user();

        $notification = PlatformNotification::where('uuid', $uuid)
            ->where('notifiable_type', AdminUser::class)
            ->where('notifiable_uuid', $admin->uuid)
            ->firstOrFail();

        $notification->markRead();

        return response()->json(['success' => true]);
    }

    public function markAllRead(): JsonResponse
    {
        /** @var AdminUser $admin */
        $admin = auth('admin')->user();

        PlatformNotification::where('notifiable_type', AdminUser::class)
            ->where('notifiable_uuid', $admin->uuid)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['success' => true]);
    }
}
