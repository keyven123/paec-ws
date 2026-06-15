<?php

namespace App\Notifications;

use App\Models\VenueInquiry;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;

class VenueEventCompletedCustomerNotification extends Notification implements ShouldQueue
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
        $venueName = $venue?->name ?? 'your venue';

        $payload = [
            'email_page_title' => 'Event Complete',
            'email_headline' => 'Congratulations on your successful event!',
            'email_intro' => 'Thank you for trusting Ticketoc and choosing '
                . $venueName
                . ' for your celebration. We hope your event was everything you imagined — '
                . 'and that you made wonderful memories with the people who matter most.',
            'email_footer_thanks' => 'Thank you for being part of the <strong style="color:#FFD700;">Ticketoc</strong> community!',
            'inquiry' => [
                'uuid' => $inquiry->uuid,
                'reference' => strtoupper(substr(str_replace('-', '', $inquiry->uuid), 0, 8)),
                'status' => VenueInquiry::customerStatusLabel($inquiry->status),
            ],
            'venue' => [
                'name' => $venueName,
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
                'completed_at' => $inquiry->completed_at?->format('l, F d, Y'),
            ],
            'view_inquiries_url' => rtrim((string) config('app.frontend_url'), '/') . '/account/inquiries',
            'browse_venues_url' => rtrim((string) config('app.frontend_url'), '/') . '/venue',
            'privacy_policy_link' => config('app.frontend_url') . '/privacy-policy',
            'tc_link' => config('app.frontend_url') . '/terms-and-conditions',
            'current_year' => Carbon::now()->format('Y'),
        ];

        return (new MailMessage())
            ->view('emails.venue-event-completed-customer-dark', ['data' => $payload])
            ->subject('[Ticketoc] Congratulations on your event at ' . $venueName);
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
