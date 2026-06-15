<?php

namespace App\Notifications;

use App\Models\TempTransaction;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TempTransactionMarketingFollowupNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public TempTransaction $tempTransaction,
    ) {
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $email = $notifiable->email ?? null;

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? ['mail'] : [];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $tempTransaction = $this->tempTransaction->loadMissing(['event']);
        $frontendUrl = rtrim((string) config('app.frontend_url'), '/');
        $checkoutUrl = $frontendUrl . '/checkout/' . $tempTransaction->uuid;

        $firstName = trim((string) ($notifiable->first_name ?? ''));
        if ($firstName === '') {
            $firstName = trim((string) ($notifiable->full_name ?? 'there'));
            if (str_contains($firstName, ' ')) {
                $firstName = explode(' ', $firstName)[0];
            }
        }

        $payload = [
            'first_name' => $firstName !== '' ? $firstName : 'there',
            'event_name' => $tempTransaction->event?->event_name ?? 'the event',
            'checkout_url' => $checkoutUrl,
            'privacy_policy_link' => $frontendUrl . '/privacy-policy',
            'tc_link' => $frontendUrl . '/terms-and-conditions',
            'current_year' => Carbon::now()->format('Y'),
        ];

        return (new MailMessage())
            ->view('emails.temp_transaction_marketing_followup', ['data' => $payload])
            ->subject('Ticketoc: Complete Your Ticket Reservation Before It Expires');
    }
}
