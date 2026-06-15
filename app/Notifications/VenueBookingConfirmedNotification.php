<?php

namespace App\Notifications;

use App\Models\Transaction;
use App\Models\VenueInquiry;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;

/**
 * Sent to the customer once a venue booking payment is confirmed. Mirrors the
 * dark "Ticketoc" email design used by the other venue notifications.
 */
class VenueBookingConfirmedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $inquiryUuid,
        public ?string $transactionUuid = null,
    ) {
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
        $inquiry = VenueInquiry::query()
            ->where('uuid', $this->inquiryUuid)
            ->with(['venueListing.featuredImage'])
            ->firstOrFail();

        $venue = $inquiry->venueListing;
        $featuredUrl = $venue?->featuredImage?->url;
        $venueImageEmbed = $this->imageForEmbed($featuredUrl);

        $transaction = $this->transactionUuid
            ? Transaction::where('uuid', $this->transactionUuid)->first()
            : $inquiry->transactions()->where('payment_status', Transaction::PAYMENT_STATUS['PAID'])->latest()->first();

        $amountPaid = $transaction?->total_amount ?? $inquiry->approved_amount;

        $payload = [
            'email_page_title' => 'Booking Confirmed',
            'email_headline' => 'Your venue booking is confirmed!',
            'email_intro' => 'Great news! Your payment has been received and your venue is now reserved. We can\'t wait to help make your event a success. Here are your booking details.',
            'email_footer_thanks' => 'Thank you for choosing <strong style="color:#FFD700;">Ticketoc</strong>!',
            'inquiry' => [
                'uuid' => $inquiry->uuid,
                'reference' => strtoupper(substr(str_replace('-', '', $inquiry->uuid), 0, 8)),
                'status' => VenueInquiry::customerStatusLabel($inquiry->status),
            ],
            'venue' => [
                'name' => $venue?->name ?? 'Venue listing',
                'city' => $venue?->city,
                'location' => $venue?->location,
                'type' => $venue?->venue_type,
                'image' => $featuredUrl,
                'image_embed' => $venueImageEmbed,
            ],
            'guest' => [
                'full_name' => $inquiry->full_name,
            ],
            'booking' => [
                'event_type' => $inquiry->event_type,
                'event_date' => $inquiry->event_date?->format('l, F d, Y'),
                'guest_count' => $inquiry->guest_count,
                'amount_paid' => $amountPaid !== null ? '₱' . number_format((float) $amountPaid, 2) : null,
                'order_number' => $transaction?->order_number,
                'paid_at' => $transaction?->paid_at?->format('F d, Y · g:i A'),
            ],
            'view_bookings_url' => rtrim((string) config('app.frontend_url'), '/') . '/account/inquiries',
            'privacy_policy_link' => config('app.frontend_url') . '/privacy-policy',
            'tc_link' => config('app.frontend_url') . '/terms-and-conditions',
            'current_year' => Carbon::now()->format('Y'),
        ];

        $venueName = $venue?->name ?? 'your venue';

        return (new MailMessage())
            ->view('emails.venue-booking-confirmed-dark', ['data' => $payload])
            ->subject('[Ticketoc] Booking confirmed for ' . $venueName);
    }

    /**
     * @return array{data: string, mime: string, cidName: string}|null
     */
    private function imageForEmbed(?string $url): ?array
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
                default => 'jpg',
            };

            return [
                'data' => $data,
                'mime' => $mime,
                'cidName' => 'venue-featured.' . $ext,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [];
    }
}
