<?php

namespace App\Notifications;

use App\Constants\GeneralConstants;
use App\Models\EventReminderLog;
use App\Models\Ticket;
use App\Models\Transaction;
use App\Services\TicketEmailExportService;
use App\Services\TicketQrPngService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;

/**
 * Sends a pre-event reminder email to the ticket holder.
 *
 * The 48-hour reminder includes ticket and coupon attachments (the customer's
 * digital ticket); the 7-day and 12-hour reminders are gentle text reminders only.
 */
class EventReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public string $transactionUuid;

    /**
     * @var array<int, string>
     */
    public array $transactionUuids;

    /**
     * @param string|array<int, string> $transactionUuids
     */
    public function __construct(
        string|array $transactionUuids,
        public string $reminderType,
    ) {
        $this->transactionUuids = array_values((array) $transactionUuids);
        $this->transactionUuid = $this->transactionUuids[0] ?? '';
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $transactions = Transaction::query()
            ->whereIn('uuid', $this->transactionUuids)
            ->with([
                'event.portraitImage',
                'event.venue',
                'schedule',
                'scheduleTime',
                'tickets.eventTicket',
                'tickets.venueSeat.venue',
                'tickets.coupons',
            ])
            ->orderBy('created_at')
            ->get();

        $transaction = $transactions->firstOrFail();

        $copy = $this->copyForType($this->reminderType);
        $includeAttachments = $this->reminderType === EventReminderLog::TYPE_48_HOURS;
        $includeQrInline = $includeAttachments;

        $qrPng = app(TicketQrPngService::class);

        $portraitUrl = $transaction->event?->portraitImage?->url;
        $eventPortraitEmbed = $this->portraitImageForEmbed($portraitUrl);

        $tickets = $transactions->flatMap(function (Transaction $ticketTransaction) use ($qrPng, $includeQrInline) {
            return $this->activeTickets($ticketTransaction)->map(function ($ticket) use ($ticketTransaction, $qrPng, $includeQrInline) {
                $qrBinary = null;
                if ($includeQrInline && ! empty($ticket->qr_code)) {
                    $qrBinary = $qrPng->pngBinary($ticket->qr_code);
                }

                return [
                    'uuid' => $ticket->uuid,
                    'transaction_uuid' => $ticketTransaction->uuid,
                    'row' => $ticket->row,
                    'col' => $ticket->col,
                    'qr_png' => $qrBinary,
                    'qr_cid_name' => 'qr-' . str_replace('-', '', (string) $ticket->uuid) . '.png',
                    'raw_qr_code' => $includeQrInline ? $ticket->qr_code : null,
                    'attendee_name' => $ticket->attendee_name,
                    'attendee_email' => $ticket->attendee_email,
                    'attendee_contact' => $ticket->attendee_contact,
                    'purchased_at' => $ticket->created_at?->format('F d, Y H:i'),
                    'status' => $ticket->status,
                    'event_date' => $ticketTransaction->schedule
                        ? $ticketTransaction->schedule->date_from?->format('F d, Y')
                        : null,
                    'event_time' => $ticketTransaction->scheduleTime
                        ? $ticketTransaction->scheduleTime->time_start . ' - ' . $ticketTransaction->scheduleTime->time_end
                        : null,
                    'order_number' => $ticketTransaction->order_number,
                    'eventTicket' => [
                        'name' => $ticket->eventTicket?->name ?? 'Regular',
                        'price' => $ticket->eventTicket?->price ?? 0,
                    ],
                ];
            });
        })->toArray();

        $payload = [
            'reminder_type' => $this->reminderType,
            'show_qr_codes' => $includeQrInline,
            'email_page_title' => $copy['page_title'],
            'email_headline' => $copy['headline'],
            'email_intro' => $copy['intro'],
            'email_body' => $copy['body'],
            'email_signoff' => $copy['signoff'],
            'email_footer_thanks' => $copy['footer_thanks'],
            'event_image' => $portraitUrl,
            'event_portrait_embed' => $eventPortraitEmbed,
            'event_name' => $transaction->event?->event_name ?? 'Your Event',
            'event_date' => $transaction->schedule
                ? $transaction->schedule->date_from?->format('F d, Y')
                : null,
            'event_time' => $transaction->scheduleTime
                ? $transaction->scheduleTime->time_start . ' - ' . $transaction->scheduleTime->time_end
                : null,
            'event_venue' => $transaction->event?->venue?->name
                ?? $transaction->event?->address
                ?? null,
            'transaction' => [
                'order_number' => $transactions->pluck('order_number')->filter()->implode(', '),
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

        $mail = (new MailMessage)
            ->subject($copy['subject']);

        if ($includeAttachments) {
            $export = app(TicketEmailExportService::class);

            $attached = 0;
            foreach ($transactions as $ticketTransaction) {
                foreach ($this->activeTickets($ticketTransaction) as $ticket) {
                    $attachment = $export->buildAttachment($ticketTransaction, $ticket);
                    if ($attachment !== null) {
                        $mail->attachData($attachment['data'], $attachment['filename'], ['mime' => $attachment['mime']]);
                        $attached++;
                    }
                }
            }
            if ($attached > 0) {
                $payload['has_ticket_file_attachments'] = true;
            }

            $couponAttached = 0;
            foreach ($transactions as $ticketTransaction) {
                foreach ($this->activeTickets($ticketTransaction) as $ticket) {
                    $couponPack = $export->buildCouponsAttachment($ticketTransaction, $ticket);
                    if ($couponPack !== null) {
                        $mail->attachData($couponPack['data'], $couponPack['filename'], ['mime' => $couponPack['mime']]);
                        $couponAttached++;
                    }
                }
            }
            if ($couponAttached > 0) {
                $payload['has_coupon_attachments'] = true;
            }
        }

        return $mail->view('emails.event-reminder-dark', ['data' => $payload]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'transaction_uuid' => $this->transactionUuid,
            'transaction_uuids' => $this->transactionUuids,
            'reminder_type' => $this->reminderType,
        ];
    }

    /**
     * @return array{subject: string, page_title: string, headline: string, intro: string, body: string, signoff: string, footer_thanks: string}
     */
    private function copyForType(string $type): array
    {
        return match ($type) {
            EventReminderLog::TYPE_7_DAYS => [
                'subject' => '[Ticketoc] Your event is just 7 days away',
                'page_title' => 'Event Reminder · 7 Days to Go',
                'headline' => 'Your event is just around the corner',
                'intro' => 'Hi there, this is a friendly reminder from Ticketoc.',
                'body' => 'Your event is just <strong style="color:#FFD700;">7 days away</strong>. We\'re excited for you! No action is needed today &mdash; we\'ll send another reminder 48 hours before with your tickets and vouchers attached, and a final note on the day itself.',
                'signoff' => 'Mark your calendar and get ready for an unforgettable experience.',
                'footer_thanks' => 'Thank you for choosing <strong style="color:#FFD700;">Ticketoc</strong>. See you at the event!',
            ],
            EventReminderLog::TYPE_48_HOURS => [
                'subject' => '[Ticketoc] Your event is in 48 hours — your ticket is attached',
                'page_title' => 'Event Reminder · 48 Hours to Go',
                'headline' => 'Your event is in 48 hours',
                'intro' => 'Hi there, your event is just <strong style="color:#FFD700;">two days away</strong>.',
                'body' => 'For your convenience, your ticket and any vouchers are attached to this email and shown below. Please present the QR code at the entrance &mdash; it serves as your entry pass.',
                'signoff' => 'We\'d recommend saving this email or downloading the attachments to your phone ahead of time.',
                'footer_thanks' => 'Thank you for choosing <strong style="color:#FFD700;">Ticketoc</strong>. See you very soon!',
            ],
            EventReminderLog::TYPE_12_HOURS => [
                'subject' => '[Ticketoc] Your event is in 12 hours',
                'page_title' => 'Event Reminder · 12 Hours to Go',
                'headline' => 'Almost time — see you in 12 hours',
                'intro' => 'Hi there, this is just a gentle reminder.',
                'body' => 'Your event begins in approximately <strong style="color:#FFD700;">12 hours</strong>. Please plan ahead for travel time and have your ticket ready in your Ticketoc account or in the email we sent two days ago.',
                'signoff' => 'Have a wonderful time. We hope you enjoy every moment.',
                'footer_thanks' => 'Thank you for choosing <strong style="color:#FFD700;">Ticketoc</strong>. Enjoy the event!',
            ],
            default => [
                'subject' => '[Ticketoc] Reminder for your upcoming event',
                'page_title' => 'Event Reminder',
                'headline' => 'A reminder for your upcoming event',
                'intro' => 'Hi there, here\'s a friendly reminder from Ticketoc.',
                'body' => 'Your event is coming up soon. We\'re looking forward to seeing you there.',
                'signoff' => 'See you at the event.',
                'footer_thanks' => 'Thank you for choosing <strong style="color:#FFD700;">Ticketoc</strong>!',
            ],
        };
    }

    /**
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
     * Only active tickets belong in reminder emails (attachments + inline QR).
     * Expired, transferred, cancelled, etc. must not be sent to the original buyer.
     *
     * @return \Illuminate\Support\Collection<int, Ticket>
     */
    private function activeTickets(Transaction $ticketTransaction)
    {
        return $ticketTransaction->tickets->filter(
            fn (Ticket $ticket) => $ticket->status === GeneralConstants::TICKET_STATUSES['ACTIVE'],
        );
    }
}
