<?php

namespace App\Notifications;

use App\Models\Ticket;
use App\Models\Transaction;
use App\Notifications\Concerns\BuildsOrderEmail;
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
    use BuildsOrderEmail;
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
                'user',
                'event.portraitImage',
                'schedule',
                'scheduleTime',
                'transactionOrders.eventTicket',
                'tickets.eventTicket',
                'tickets.eventLocation',
                'tickets.venueSeat.venue',
                'tickets.coupons',
            ])
            ->firstOrFail();

        $qrPng = app(TicketQrPngService::class);

        $portraitUrl = $transaction->event?->portraitImage?->url;
        $scheduleTime = $transaction->scheduleTime;
        $eventTime = $scheduleTime
            ? Carbon::parse($scheduleTime->time_start)->format('g:i A') . ' – ' . Carbon::parse($scheduleTime->time_end)->format('g:i A')
            : null;

        $tickets = $transaction->tickets->map(fn (Ticket $ticket) => [
            'uuid' => $ticket->uuid,
            'ticket_number' => $ticket->ticket_number,
            'type' => $ticket->eventTicket?->name ?? 'General Admission',
            'attendee_name' => $ticket->attendee_name,
            'visit_date' => $ticket->resolveDateOfVisit()?->format('D, F j, Y'),
            'location' => $ticket->eventLocation?->displayLabel() ?? $transaction->event?->address,
            'seat' => $ticket->row !== null && $ticket->col !== null ? "{$ticket->row}-{$ticket->col}" : null,
            'raw_qr_code' => $ticket->qr_code,
            'qr_png' => $qrPng->pngBinary($ticket->qr_code),
            'qr_cid_name' => 'qr-' . str_replace('-', '', (string) $ticket->uuid) . '.png',
        ])->all();

        $isUpgrade = $this->context === self::CONTEXT_UPGRADE;

        $payload = array_merge($this->orderSummary($transaction), [
            'email_context' => $this->context,
            'email_page_title' => $isUpgrade ? 'Ticket upgraded' : 'Booking confirmed',
            'headline' => $isUpgrade ? 'Your ticket has been upgraded' : 'Your booking is confirmed',
            'intro' => $isUpgrade
                ? 'Your upgrade is complete. Your updated ticket is below and attached to this email.'
                : 'Thank you for booking with PAEC. Your payment was received and your tickets are ready.',
            'event_image' => $portraitUrl,
            'event_portrait_embed' => $this->portraitImageForEmbed($portraitUrl),
            'event_time' => $eventTime,
            'paid_at' => $transaction->paid_at?->timezone('Asia/Manila')->format('F j, Y g:i A'),
            'tickets' => $tickets,
            'has_ticket_file_attachments' => false,
        ]);

        $subject = $isUpgrade
            ? "Your PAEC ticket has been upgraded – Order {$transaction->order_number}"
            : "Your PAEC booking is confirmed – Order {$transaction->order_number}";

        $mail = (new MailMessage())->subject($subject);

        $export = app(TicketEmailExportService::class);
        foreach ($transaction->tickets as $ticket) {
            $attachment = $export->buildAttachment($transaction, $ticket);
            if ($attachment !== null) {
                $mail->attachData($attachment['data'], $attachment['filename'], ['mime' => $attachment['mime']]);
                $payload['has_ticket_file_attachments'] = true;
            }

            $couponPack = $export->buildCouponsAttachment($transaction, $ticket);
            if ($couponPack !== null) {
                $mail->attachData($couponPack['data'], $couponPack['filename'], ['mime' => $couponPack['mime']]);
            }
        }

        return $this->withPaecCopy($mail->view('emails.paec.booking-confirmed', ['data' => $payload]));
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
