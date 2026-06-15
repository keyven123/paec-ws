<?php

namespace App\Notifications;

use App\Models\VenueInquiry;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;

/**
 * Sent to a customer whose venue inquiry was auto-cancelled because another
 * booking for the same venue on the same date was confirmed first. Friendly,
 * regretful tone with a clear call to action to pick a different date.
 */
class VenueBookingUnavailableNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $inquiryUuid)
    {
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

        $venueUrl = $venue?->slug
            ? rtrim((string) config('app.frontend_url'), '/') . '/venue/' . $venue->slug
            : rtrim((string) config('app.frontend_url'), '/') . '/venue';

        $payload = [
            'email_page_title' => 'Date No Longer Available',
            'email_headline' => 'We\'re so sorry — that date just got booked',
            'email_intro' => 'We truly wish we had better news. The date you were hoping for at this venue has just been booked by another guest, so we\'ve had to release your inquiry. We know how much planning goes into your event, and we\'d love to help you find another perfect date.',
            'email_footer_thanks' => 'Thank you for your understanding, and for choosing <strong style="color:#FFD700;">Ticketoc</strong>.',
            'inquiry' => [
                'reference' => strtoupper(substr(str_replace('-', '', $inquiry->uuid), 0, 8)),
            ],
            'venue' => [
                'name' => $venue?->name ?? 'the venue',
                'city' => $venue?->city,
                'location' => $venue?->location,
                'type' => $venue?->venue_type,
                'image' => $featuredUrl,
                'image_embed' => $venueImageEmbed,
            ],
            'guest' => [
                'full_name' => $inquiry->full_name,
            ],
            'event' => [
                'type' => $inquiry->event_type,
                'date' => $inquiry->event_date?->format('l, F d, Y'),
                'guest_count' => $inquiry->guest_count,
            ],
            'venue_url' => $venueUrl,
            'privacy_policy_link' => config('app.frontend_url') . '/privacy-policy',
            'tc_link' => config('app.frontend_url') . '/terms-and-conditions',
            'current_year' => Carbon::now()->format('Y'),
        ];

        $venueName = $venue?->name ?? 'your venue';

        return (new MailMessage())
            ->view('emails.venue-booking-unavailable-dark', ['data' => $payload])
            ->subject('[Ticketoc] An update about your inquiry for ' . $venueName);
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
