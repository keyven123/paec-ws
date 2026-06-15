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

class VenueVisitScheduledNotification extends Notification implements ShouldQueue
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

        $visitTime = $inquiry->visit_scheduled_time
            ? substr((string) $inquiry->visit_scheduled_time, 0, 5)
            : null;

        $visitDateFormatted = $inquiry->visit_scheduled_date?->format('l, F d, Y');
        $visitTimeFormatted = $this->formatVisitTime($visitTime);
        $visitScheduleLabel = VenueInquiryResource::visitScheduledLabel(
            $inquiry->visit_scheduled_date?->format('Y-m-d'),
            $visitTime,
        );

        $payload = [
            'email_page_title' => 'Site Visit Scheduled',
            'email_headline' => 'Your site visit has been scheduled',
            'email_intro' => 'Good news! The venue team has confirmed your site visit. Please review the date and time below and arrive on schedule so you can explore the space and discuss your event plans.',
            'email_footer_thanks' => 'Thank you for choosing <strong style="color:#FFD700;">Ticketoc</strong>!',
            'inquiry' => [
                'uuid' => $inquiry->uuid,
                'reference' => strtoupper(substr(str_replace('-', '', $inquiry->uuid), 0, 8)),
                'status' => VenueInquiry::customerStatusLabel($inquiry->status),
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
            ],
            'visit' => [
                'date' => $visitDateFormatted,
                'time' => $visitTimeFormatted,
                'label' => $visitScheduleLabel,
            ],
            'event' => [
                'type' => $inquiry->event_type,
                'date' => $inquiry->event_date?->format('l, F d, Y'),
                'guest_count' => $inquiry->guest_count,
                'message' => $inquiry->message,
            ],
            'view_inquiries_url' => rtrim((string) config('app.frontend_url'), '/') . '/account/inquiries',
            'privacy_policy_link' => config('app.frontend_url') . '/privacy-policy',
            'tc_link' => config('app.frontend_url') . '/terms-and-conditions',
            'current_year' => Carbon::now()->format('Y'),
        ];

        $venueName = $venue?->name ?? 'your venue';

        return (new MailMessage())
            ->view('emails.venue-visit-scheduled-dark', ['data' => $payload])
            ->subject('[Ticketoc] Site visit scheduled for ' . $venueName);
    }

    private function formatVisitTime(?string $time): ?string
    {
        if ($time === null || $time === '') {
            return null;
        }

        [$hours, $minutes] = explode(':', $time);
        $hour = (int) $hours;
        $ampm = $hour >= 12 ? 'PM' : 'AM';
        $hour12 = $hour % 12 ?: 12;

        return sprintf('%d:%s %s', $hour12, $minutes, $ampm);
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
