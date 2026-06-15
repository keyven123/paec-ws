<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EmailVerificationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected $confirmationToken;

    /**
     * Create a new notification instance.
     */
    public function __construct($confirmationToken)
    {
        $this->confirmationToken = $confirmationToken;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $appName = config('app.name');
        $frontend = rtrim((string) config('app.frontend_url', config('app.url')), '/');
        $ttlMinutes = (int) config('auth.confirmation_token_ttl', 60);

        // Resolve copy in PHP so queued mail always has strings (avoids missing __() in some render paths).
        $copy = [
            'subject_title' => __('messages.verification.subject', ['app_name' => $appName]),
            'greeting' => __('messages.verification.greeting', ['first_name' => $notifiable->first_name]),
            'intro_line_1' => __('messages.verification.intro_line_1', ['app_name' => $appName]),
            'intro_line_2' => __('messages.verification.intro_line_2'),
            'instruction_copy' => __('messages.verification.instructions.copy_code'),
            'instruction_go' => __('messages.verification.instructions.go_to_verification_page'),
            'instruction_enter' => __('messages.verification.instructions.enter_email_and_code'),
            'instruction_click' => __('messages.verification.instructions.click_verify_email'),
            'important' => __('messages.verification.important', [
                'ttl' => $ttlMinutes,
                'app_name' => $appName,
            ]),
            'salutation' => __('messages.verification.salutation', ['app_name' => $appName]),
            'automated_message' => __('messages.verification.automated_message'),
        ];

        return (new MailMessage())
            ->subject(__('emails.verification.subject', ['app_name' => $appName]))
            ->view('emails.email-verification', [
                'notifiable' => $notifiable,
                'confirmationToken' => $this->confirmationToken,
                'privacy_policy_link' => $frontend !== '' ? "{$frontend}/privacy-policy" : '#',
                'tc_link' => $frontend !== '' ? "{$frontend}/terms-and-conditions" : '#',
                'copy' => $copy,
            ]);
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            //
        ];
    }
}