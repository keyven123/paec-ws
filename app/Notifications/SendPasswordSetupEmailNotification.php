<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Carbon;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class SendPasswordSetupEmailNotification extends Notification implements ShouldQueue
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
        $url = config('app.frontend_url');

        // Get user name from password setup email
        $email = $notifiable->receiver ?? null;
        $name = '';

        // Try to get AdminUser by email
        $adminUser = \App\Models\AdminUser::where('email', $email)->first();
        if ($adminUser) {
            $name = $adminUser->full_name ?? '';
        } else {
            // Try to get User by email
            $user = \App\Models\User::where('email', $email)->first();
            if ($user) {
                $name = $user->full_name ?? '';
            }
        }

        $payload = [
            'privacy_policy_link' => $url . '/privacy-policy',
            'tc_link' => $url . '/terms-and-conditions',
            'url_link' => $url . '/update_password?uuid=' . $notifiable->uuid,
            'current_year' => Carbon::now()->format('Y'),
            'name' => $name,
            'logo_url' => $url . '/images/logo/ticketoc_dark_nobg.png',
            'message' => 'Your password needs to be set. Click the link below to set a new one.',
        ];

        return (new MailMessage())
            ->view('emails.password_set_email', ['data' => $payload])
            ->subject('[Ticketoc] Set Your Password');
    }
}
