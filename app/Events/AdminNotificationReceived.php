<?php

namespace App\Events;

use App\Models\AdminUser;
use App\Models\PlatformNotification;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast to the admin user's personal notifications channel so the
 * bell badge updates in real-time on the merchant / superadmin dashboard.
 * Customer notifications are NOT broadcast (polling / on-load is sufficient).
 */
class AdminNotificationReceived implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly PlatformNotification $notification,
        public readonly AdminUser $recipient,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("notifications.admin.{$this->recipient->uuid}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'admin.notification.received';
    }

    public function broadcastWith(): array
    {
        return [
            'uuid'       => $this->notification->uuid,
            'type'       => $this->notification->type,
            'title'      => $this->notification->title,
            'body'       => $this->notification->body,
            'action_url' => $this->notification->action_url,
            'data'       => $this->notification->data,
            'read_at'    => null,
            'created_at' => $this->notification->created_at?->toIso8601String(),
        ];
    }
}
