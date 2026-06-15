<?php

namespace App\Notifications;

use App\Models\VenueInquiry;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;

class VenueBalancePaidNotification extends Notification implements ShouldQueue
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
        $balanceTotal = (float) $inquiry->balance_amount + (float) $inquiry->additional_charges;
        $balanceLabel = '₱' . number_format($balanceTotal, 2);
        $depositLabel = $inquiry->deposit_amount !== null
            ? '₱' . number_format((float) $inquiry->deposit_amount, 2)
            : null;
        $manageUrl = rtrim((string) config('app.frontend_url'), '/')
            . '/general-admin/venues/' . ($venue?->uuid ?? '')
            . '?openInquiry=' . $inquiry->uuid;

        $payload = [
            'email_page_title' => 'Balance Paid',
            'email_headline' => 'Final balance received — booking fully confirmed',
            'email_intro' => ($inquiry->full_name ?? 'A customer')
                . ' has paid the remaining balance for '
                . ($venue?->name ?? 'your venue')
                . '. The booking is now fully paid and the event date is confirmed.',
            'email_footer_thanks' => 'Thank you for partnering with <strong style="color:#FFD700;">Ticketoc</strong>!',
            'inquiry' => [
                'uuid' => $inquiry->uuid,
                'reference' => strtoupper(substr(str_replace('-', '', $inquiry->uuid), 0, 8)),
                'status' => 'Fully Paid',
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
            ],
            'payment' => [
                'balance_label' => $balanceLabel,
                'deposit_label' => $depositLabel,
                'additional_charges_label' => (float) $inquiry->additional_charges > 0
                    ? '₱' . number_format((float) $inquiry->additional_charges, 2)
                    : null,
                'paid_at' => $inquiry->fully_paid_at?->format('l, F d, Y g:i A'),
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

        $venueName = $venue?->name ?? 'your venue';

        return (new MailMessage())
            ->view('emails.venue-balance-paid-dark', ['data' => $payload])
            ->subject('[Ticketoc] Final balance received for ' . $venueName);
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
