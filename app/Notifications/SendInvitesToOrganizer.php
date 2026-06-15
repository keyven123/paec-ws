<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SendInvitesToOrganizer extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(public string $secret)
    {
        $this->secret = $secret;
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
        $payload = [
            'url' => config('app.frontend_url') . '/organizer/onboarding?secret=' . $this->secret . '&email=' . $notifiable->email,
            'greeting' => __('emails.sent_invites.greeting', ['name' => $notifiable->name]),
            'intro_line_1' => __('emails.sent_invites.intro_line_1', ['representative_name' => $notifiable->representative_first_name . ' ' . $notifiable->representative_last_name]),
            'intro_line_2' => __('emails.sent_invites.intro_line_2'),
            'intro_line_3' => __('emails.sent_invites.intro_line_3'),
            'outro_line_1' => __('emails.sent_invites.outro_line_1'),
            'outro_line_2' => __('emails.sent_invites.outro_line_2', ['support_email' => config('app.support_email')]),
            'outro_line_3' => __('emails.sent_invites.outro_line_3'),
            'salutation' => __('emails.sent_invites.salutation', ['app_name' => config('app.name')]),
            'automated_message' => __('emails.sent_invites.automated_message'),
            'instructions' => [
                'button' => __('emails.sent_invites.instructions.button'),
                'fill_up_details' => __('emails.sent_invites.instructions.fill_up_details'),
                'submit' => __('emails.sent_invites.instructions.submit'),
                'success' => __('emails.sent_invites.instructions.success'),
            ],
            'important' => __('emails.sent_invites.important', ['ttl' => 24]),
            'button' => __('emails.sent_invites.button')
        ];

        return (new MailMessage())
            ->view("emails.sent-invites", ['data' => $payload])
            ->subject(__('emails.sent_invites.subject', ['app_name' => config('app.name')]));
    }
}
