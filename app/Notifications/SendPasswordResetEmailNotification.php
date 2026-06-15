<?php

namespace App\Notifications;

use App\Models\AdminUser;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

class SendPasswordResetEmailNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Construct
     *
     */
    public function __construct()
    {
    }

    /**
     * Get the notification channels.
     *
     * @param  mixed  $notifiable
     * @return array|string
     */
    public function via($notifiable)
    {
        $to = method_exists($notifiable, 'routeNotificationForMail')
            ? $notifiable->routeNotificationForMail($this)
            : ($notifiable->receiver ?? null);

        return filter_var($to, FILTER_VALIDATE_EMAIL) ? ['mail'] : [];
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toMail($notifiable): MailMessage
    {
        if ($notifiable->otpable->resettable_type == 'App\Models\AdminUser') {
            $user = AdminUser::whereUuid($notifiable->otpable->resettable_id)->first();
        } else {
            $user = User::whereUuid($notifiable->otpable->resettable_id)->first();
        }
        $url = config('app.frontend_url');

        // Determine the referrer based on user type
        $referrer = 'home';
        if ($notifiable->otpable->resettable_type == 'App\Models\AdminUser') {
            $adminUser = AdminUser::whereUuid($notifiable->otpable->resettable_id)->first();
            if ($adminUser && $adminUser->role && $adminUser->role->is_admin) {
                $referrer = 'admin';
            } else {
                $referrer = 'organizer';
            }
        }

        $payload = [
            'privacy_policy_link' => $url . '/privacy_policy',
            'tc_link' => $url . '/privacy_policy',
            'url_link' => $url . '/set-password?uuid=' . $notifiable->uuid . '&referrer=' . $referrer,
            'current_year' => Carbon::now()->format('Y'),
            'name' => $user->full_name ?? '',
            'logo_url' => $url . '/images/logo/ticketoc_dark_nobg.png'
        ];

        return (new MailMessage())
            ->view('emails.password_reset_email', ['data' => $payload])
            ->subject('[Ticketoc] Reset your password');
    }
}
