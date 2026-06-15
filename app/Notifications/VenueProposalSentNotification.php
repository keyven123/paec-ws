<?php

namespace App\Notifications;

use App\Models\Upload;
use App\Models\VenueInquiry;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class VenueProposalSentNotification extends Notification implements ShouldQueue
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
        $amountLabel = $inquiry->proposal_amount !== null
            ? '₱' . number_format((float) $inquiry->proposal_amount, 2)
            : null;
        $validUntil = $inquiry->proposal_valid_until?->format('l, F d, Y');
        $viewInquiriesUrl = rtrim((string) config('app.frontend_url'), '/')
            . '/account/inquiries?openChat=' . $inquiry->uuid;

        $payload = [
            'email_page_title' => 'Venue Proposal',
            'email_headline' => 'You have received a venue proposal',
            'email_intro' => 'The venue team has shared a proposal for your inquiry. Review the amount and validity below, then open the attached file for full details. You can accept or continue the conversation inside Ticketoc.',
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
            'proposal' => [
                'amount_label' => $amountLabel,
                'valid_until' => $validUntil,
            ],
            'event' => [
                'type' => $inquiry->event_type,
                'date' => $inquiry->event_date?->format('l, F d, Y'),
                'guest_count' => $inquiry->guest_count,
            ],
            'view_inquiries_url' => $viewInquiriesUrl,
            'privacy_policy_link' => config('app.frontend_url') . '/privacy-policy',
            'tc_link' => config('app.frontend_url') . '/terms-and-conditions',
            'current_year' => Carbon::now()->format('Y'),
        ];

        $venueName = $venue?->name ?? 'your venue';

        $mail = (new MailMessage())
            ->view('emails.venue-proposal-sent-dark', ['data' => $payload])
            ->subject('[Ticketoc] Proposal received for ' . $venueName);

        $upload = $inquiry->proposal_upload_uuid
            ? Upload::query()->find($inquiry->proposal_upload_uuid)
            : null;

        if ($upload !== null) {
            $this->attachUpload($mail, $upload);
        }

        return $mail;
    }

    private function attachUpload(MailMessage $mail, Upload $upload): void
    {
        try {
            $diskName = $upload->disk ?: config('filesystems.default');
            $disk = Storage::disk($diskName);

            if (! $disk->exists($upload->path)) {
                return;
            }

            $filename = $upload->name ?: basename((string) $upload->path);
            if ($upload->extension && ! str_contains($filename, '.')) {
                $filename .= '.' . $upload->extension;
            }

            $mail->attachData(
                $disk->get($upload->path),
                $filename,
                ['mime' => $upload->mime_type ?: 'application/octet-stream'],
            );
        } catch (\Throwable) {
            // Email still sends without attachment if storage read fails.
        }
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
