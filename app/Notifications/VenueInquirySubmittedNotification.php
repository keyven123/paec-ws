<?php

namespace App\Notifications;

use App\Http\Resources\VenueInquiryResource;
use App\Models\VenueInquiry;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;

class VenueInquirySubmittedNotification extends Notification implements ShouldQueue
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
            ->with(['venueListing.featuredImage', 'venueListing.organization'])
            ->firstOrFail();

        $venue = $inquiry->venueListing;
        $featuredUrl = $venue?->featuredImage?->url;
        $venueImageEmbed = $this->imageForEmbed($featuredUrl);

        $siteVisit = $inquiry->site_visit;
        $siteVisitLabel = VenueInquiryResource::siteVisitLabel($siteVisit) ?? 'Not specified';

        $payload = [
            'email_page_title' => 'New Venue Inquiry',
            'email_headline' => 'New inquiry received',
            'email_intro' => 'A guest has submitted a new venue inquiry through your Ticketoc listing. Review the details below and respond promptly to secure this booking.',
            'email_footer_thanks' => 'Thank you for partnering with <strong style="color:#FFD700;">Ticketoc</strong>!',
            'inquiry' => [
                'uuid' => $inquiry->uuid,
                'reference' => strtoupper(substr(str_replace('-', '', $inquiry->uuid), 0, 8)),
                'status' => ucfirst(str_replace('_', ' ', $inquiry->status)),
                'submitted_at' => $inquiry->created_at?->format('F d, Y · g:i A'),
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
                'email' => $inquiry->email,
                'phone' => $inquiry->phone,
            ],
            'event' => [
                'type' => $inquiry->event_type,
                'date' => $inquiry->event_date?->format('l, F d, Y'),
                'date_short' => $inquiry->event_date?->format('M d, Y'),
                'guest_count' => $inquiry->guest_count,
                'site_visit' => $siteVisitLabel,
                'site_visit_requested' => $siteVisit === VenueInquiry::SITE_VISIT_YES,
                'message' => $inquiry->message,
            ],
            'manage_inquiry_url' => $venue
                ? rtrim((string) config('app.frontend_url'), '/') . '/general-admin/venues/' . $venue->uuid
                : rtrim((string) config('app.frontend_url'), '/') . '/general-admin/venues',
            'privacy_policy_link' => config('app.frontend_url') . '/privacy-policy',
            'tc_link' => config('app.frontend_url') . '/terms-and-conditions',
            'current_year' => Carbon::now()->format('Y'),
        ];

        $venueName = $venue?->name ?? 'your venue';

        return (new MailMessage())
            ->view('emails.venue-inquiry-submitted-dark', ['data' => $payload])
            ->subject('[Ticketoc] New inquiry for ' . $venueName);
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
