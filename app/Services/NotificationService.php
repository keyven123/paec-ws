<?php

namespace App\Services;

use App\Events\AdminNotificationReceived;
use App\Models\AdminUser;
use App\Models\PlatformNotification;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class NotificationService
{
    /**
     * Create a notification for any notifiable model (User or AdminUser).
     * AdminUser notifications are also broadcast over Reverb in real-time.
     *
     * @param  User|AdminUser  $notifiable
     * @param  string  $type      Machine-readable type, e.g. 'ticket_purchase'
     * @param  string  $title     Short human-readable title
     * @param  string|null  $body  Optional longer description
     * @param  string|null  $actionUrl  Frontend path to navigate to on click
     * @param  array  $data       Extra key→value context payload
     */
    public function send(
        Model $notifiable,
        string $type,
        string $title,
        ?string $body = null,
        ?string $actionUrl = null,
        array $data = [],
    ): PlatformNotification {
        $notification = PlatformNotification::create([
            'notifiable_type' => get_class($notifiable),
            'notifiable_uuid' => $notifiable->uuid,
            'type'            => $type,
            'title'           => $title,
            'body'            => $body,
            'action_url'      => $actionUrl,
            'data'            => empty($data) ? null : $data,
        ]);

        // Broadcast in real-time only for admin/merchant users.
        if ($notifiable instanceof AdminUser) {
            AdminNotificationReceived::dispatch($notification, $notifiable);
        }

        return $notification;
    }

    /**
     * Convenience: send the same notification to every AdminUser in an
     * organization. Useful for notifying all merchant staff at once.
     */
    public function sendToOrganization(
        string $organizationUuid,
        string $type,
        string $title,
        ?string $body = null,
        ?string $actionUrl = null,
        array $data = [],
    ): void {
        $admins = AdminUser::where('organization_uuid', $organizationUuid)->get();

        foreach ($admins as $admin) {
            $this->send($admin, $type, $title, $body, $actionUrl, $data);
        }
    }
}
