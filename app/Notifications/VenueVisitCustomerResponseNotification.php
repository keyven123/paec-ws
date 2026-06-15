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

class VenueVisitCustomerResponseNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const RESPONSE_ACCEPTED = 'accepted';

    public const RESPONSE_DECLINED = 'declined';

    public const RESPONSE_SUGGESTED = 'suggested';

    public function __construct(
        public string $inquiryUuid,
        public string $response,
        public ?string $suggestedDate = null,
        public ?string $scheduledVisitLabel = null,
        public ?string $suggestedTime = null,
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
        $manageUrl = rtrim((string) config('app.frontend_url'), '/')
            . '/general-admin/venues/' . ($venue?->uuid ?? '')
            . '?openInquiry=' . $inquiry->uuid;

        $visitTime = $inquiry->visit_scheduled_time
            ? substr((string) $inquiry->visit_scheduled_time, 0, 5)
            : null;
        $scheduledVisitLabel = $this->scheduledVisitLabel ?? VenueInquiryResource::visitScheduledLabel(
            $inquiry->visit_scheduled_date?->format('Y-m-d'),
            $visitTime,
        );
        $suggestedDateLabel = $this->suggestedDate
            ? Carbon::parse($this->suggestedDate)->format('l, F d, Y')
            : null;
        $suggestedTimeLabel = $this->suggestedTime
            ? Carbon::parse($this->suggestedTime)->format('g:i A')
            : null;
        $suggestedVisitLabel = VenueInquiryResource::visitScheduledLabel(
            $suggestedDateLabel,
            $suggestedTimeLabel,
        );

        [$headline, $intro, $subject, $statusLabel, $highlightLabel, $highlightValue, $tip] = match ($this->response) {
            self::RESPONSE_ACCEPTED => [
                'Customer confirmed the site visit',
                'Good news! The customer has accepted the scheduled site visit. Prepare the venue and continue the conversation in Ticketoc if anything else is needed.',
                'Site visit accepted by customer',
                'Site Visit Scheduled',
                'Confirmed visit',
                $scheduledVisitLabel ?? 'Scheduled visit',
                'The customer is expecting to visit on the scheduled date. Reach out in chat if you need to confirm arrival details.',
            ],
            self::RESPONSE_SUGGESTED => [
                'Customer suggested a different visit date',
                'The customer could not make the scheduled site visit and has suggested an alternative date and time. Review the details below and send a new visit schedule when ready.',
                'Customer suggested a new site visit date',
                'In Discussion',
                'Suggested date & time',
                $suggestedVisitLabel ?? 'Alternative date & time',
                'Open the inquiry chat to confirm the new date and time or propose another schedule.',
            ],
            default => [
                'Customer declined the site visit',
                'The customer has declined the scheduled site visit. The inquiry is back in discussion — follow up in chat to agree on a new date or next steps.',
                'Site visit declined by customer',
                'In Discussion',
                'Declined visit',
                $scheduledVisitLabel ?? 'Scheduled visit',
                'You can propose a new visit date from the inquiry workflow when the customer is ready.',
            ],
        };

        $venueName = $venue?->name ?? 'your venue';

        $payload = [
            'email_page_title' => $subject,
            'email_headline' => $headline,
            'email_intro' => $intro,
            'email_footer_thanks' => 'Thank you for partnering with <strong style="color:#FFD700;">Ticketoc</strong>!',
            'response_type' => $this->response,
            'highlight_label' => $highlightLabel,
            'highlight_value' => $highlightValue,
            'tip' => $tip,
            'inquiry' => [
                'uuid' => $inquiry->uuid,
                'reference' => strtoupper(substr(str_replace('-', '', $inquiry->uuid), 0, 8)),
                'status' => $statusLabel,
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
                'email' => $inquiry->email,
            ],
            'visit' => [
                'scheduled_label' => $scheduledVisitLabel,
                'suggested_date' => $suggestedDateLabel,
                'suggested_time' => $suggestedTimeLabel,
                'suggested_label' => $suggestedVisitLabel,
            ],
            'event' => [
                'type' => $inquiry->event_type,
                'date' => $inquiry->event_date?->format('l, F d, Y'),
                'guest_count' => $inquiry->guest_count,
            ],
            'manage_inquiry_url' => $manageUrl,
            'privacy_policy_link' => config('app.frontend_url') . '/privacy-policy',
            'tc_link' => config('app.frontend_url') . '/terms-and-conditions',
            'current_year' => Carbon::now()->format('Y'),
        ];

        return (new MailMessage())
            ->view('emails.venue-visit-customer-response-merchant-dark', ['data' => $payload])
            ->subject('[Ticketoc] ' . $subject . ' — ' . $venueName);
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
