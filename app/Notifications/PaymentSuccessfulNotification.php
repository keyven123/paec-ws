<?php

namespace App\Notifications;

use App\Models\Transaction;
use App\Services\TicketEmailExportService;
use App\Services\TicketQrPngService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;

class PaymentSuccessfulNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const CONTEXT_PAYMENT = 'payment';

    public const CONTEXT_UPGRADE = 'upgrade';

    /**
     * @param  string  $context  {@see self::CONTEXT_PAYMENT} (checkout) or {@see self::CONTEXT_UPGRADE} (ticket upgrade)
     */
    public function __construct(
        public string $transactionUuid,
        public string $context = self::CONTEXT_PAYMENT,
    ) {
    }

    /**
     * Fetch a remote image for CID embedding. data: URIs in HTML are often stripped by SendGrid / Gmail;
     * inline attachments work reliably.
     *
     * @return array{data: string, mime: string, cidName: string}|null
     */
    private function portraitImageForEmbed(?string $url): ?array
    {
        if ($url === null || $url === '') {
            return null;
        }

        try {
            $response = Http::timeout(20)->withHeaders(['Accept' => 'image/*'])->get($url);
            if (! $response->successful()) {
                return null;
            }
            $data = $response->body();
            if ($data === '') {
                return null;
            }
            $mime = $response->header('Content-Type');
            if (! is_string($mime) || ! str_starts_with($mime, 'image/')) {
                $mime = 'image/jpeg';
            }
            $mime = trim(explode(';', $mime)[0]);
            $ext = match (true) {
                str_contains($mime, 'png') => 'png',
                str_contains($mime, 'gif') => 'gif',
                str_contains($mime, 'webp') => 'webp',
                str_contains($mime, 'svg') => 'svg',
                default => 'jpg',
            };

            return [
                'data' => $data,
                'mime' => $mime,
                'cidName' => 'event-portrait.' . $ext,
            ];
        } catch (\Throwable) {
            return null;
        }
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
        $transaction = Transaction::query()
            ->where('uuid', $this->transactionUuid)
            ->with([
                'event.portraitImage',
                'schedule',
                'scheduleTime',
                'tickets.eventTicket',
                'tickets.venueSeat.venue',
                'tickets.coupons',
            ])
            ->firstOrFail();

        $qrPng = app(TicketQrPngService::class);

        $portraitUrl = $transaction->event->portraitImage?->url;
        $eventPortraitEmbed = $this->portraitImageForEmbed($portraitUrl);

        $tickets = $transaction->tickets->map(function ($ticket) use ($transaction, $qrPng) {
            $qrBinary = null;
            if (! empty($ticket->qr_code)) {
                $qrBinary = $qrPng->pngBinary($ticket->qr_code);
            }

            return [
                'uuid' => $ticket->uuid,
                'row' => $ticket->row,
                'col' => $ticket->col,
                'qr_png' => $qrBinary,
                'qr_cid_name' => 'qr-' . str_replace('-', '', (string) $ticket->uuid) . '.png',
                'raw_qr_code' => $ticket->qr_code,
                'attendee_name' => $ticket->attendee_name,
                'attendee_email' => $ticket->attendee_email,
                'attendee_contact' => $ticket->attendee_contact,
                'purchased_at' => $ticket->created_at?->format('F d, Y H:i'),
                'status' => $ticket->status,
                'event_date' => $transaction->schedule
                    ? $transaction->schedule->date_from?->format('F d, Y')
                    : null,
                'event_time' => $transaction->scheduleTime
                    ? $transaction->scheduleTime->time_start . ' - ' . $transaction->scheduleTime->time_end
                    : null,
                'eventTicket' => [
                    'name' => $ticket->eventTicket?->name ?? 'Regular',
                    'price' => $ticket->eventTicket?->price ?? 0,
                ],
            ];
        })->toArray();

        $isUpgrade = $this->context === self::CONTEXT_UPGRADE;

        $payload = [
            'email_context' => $this->context,
            'email_page_title' => $isUpgrade ? 'Ticket upgraded' : 'Payment Successful',
            'email_headline' => $isUpgrade ? '🎟️ Ticket upgraded successfully' : '🎟️ Payment Successful',
            'email_footer_thanks' => $isUpgrade
                ? 'Thank you for using <strong style="color:#FFD700;">Ticketoc</strong>! Your upgraded ticket and coupons are below.'
                : 'Thank you for purchasing with <strong style="color:#FFD700;">Ticketoc</strong>!',
            'event_image' => $portraitUrl,
            'event_portrait_embed' => $eventPortraitEmbed,
            'event_name' => $transaction->event->event_name,
            'event_date' => $transaction->schedule
                ? $transaction->schedule->date_from?->format('F d, Y')
                : null,
            'event_time' => $transaction->scheduleTime
                ? $transaction->scheduleTime->time_start . ' - ' . $transaction->scheduleTime->time_end
                : null,
            'transaction' => [
                'order_number' => $transaction->order_number,
                'total_amount' => $transaction->total_amount,
                'payment_status' => $transaction->payment_status,
                'order_status' => $transaction->order_status,
                'paid_at' => $transaction->paid_at
                    ? Carbon::parse($transaction->paid_at)->format('F d, Y H:i')
                    : null,
            ],
            'tickets' => $tickets,
            'privacy_policy_link' => config('app.frontend_url') . '/privacy-policy',
            'tc_link' => config('app.frontend_url') . '/terms-and-conditions',
            'current_year' => Carbon::now()->format('Y'),
            'view_ticket' => rtrim((string) config('app.frontend_url'), '/') . '/account/tickets',
            'view_coupons' => rtrim((string) config('app.frontend_url'), '/') . '/account/coupons',
            'has_ticket_file_attachments' => false,
            'has_coupon_attachments' => false,
        ];

        $subject = $isUpgrade
            ? '[Ticketoc] Your ticket has been upgraded!'
            : '[Ticketoc] Your payment has been successful!';

        $mail = (new MailMessage())
            ->view('emails.payment-successful-dark-v2', ['data' => $payload])
            ->subject($subject);

        $export = app(TicketEmailExportService::class);
        $attached = 0;
        foreach ($transaction->tickets as $ticket) {
            $attachment = $export->buildAttachment($transaction, $ticket);
            if ($attachment !== null) {
                $mail->attachData($attachment['data'], $attachment['filename'], ['mime' => $attachment['mime']]);
                $attached++;
            }
        }

        if ($attached > 0) {
            $payload['has_ticket_file_attachments'] = true;
        }

        $couponAttached = 0;
        foreach ($transaction->tickets as $ticket) {
            $couponPack = $export->buildCouponsAttachment($transaction, $ticket);
            if ($couponPack !== null) {
                $mail->attachData($couponPack['data'], $couponPack['filename'], ['mime' => $couponPack['mime']]);
                $couponAttached++;
            }
        }

        if ($couponAttached > 0) {
            $payload['has_coupon_attachments'] = true;
        }

        return $mail;
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
