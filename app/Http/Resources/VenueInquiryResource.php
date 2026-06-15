<?php

namespace App\Http\Resources;

use App\Models\VenueInquiry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VenueInquiryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $siteVisit = $this->resolveSiteVisit();
        $revealContact = $this->shouldRevealContactDetails($request);

        return [
            'uuid' => $this->uuid,
            'venue_listing_uuid' => $this->venue_listing_uuid,
            'full_name' => $this->full_name,
            'email' => $revealContact ? $this->email : null,
            'phone' => $revealContact ? $this->phone : null,
            'event_type' => $this->event_type,
            'event_date' => $this->event_date?->format('Y-m-d'),
            'guest_count' => $this->guest_count,
            'site_visit' => $siteVisit,
            'site_visit_label' => self::siteVisitLabel($siteVisit),
            'visit_scheduled_date' => $this->visit_scheduled_date?->format('Y-m-d'),
            'visit_scheduled_time' => $this->formatVisitScheduledTime(),
            'visit_scheduled_label' => self::visitScheduledLabel(
                $this->visit_scheduled_date?->format('Y-m-d'),
                $this->formatVisitScheduledTime(),
            ),
            'message' => $this->message,
            'status' => $this->status,
            'status_label' => VenueInquiry::statusLabel($this->status),
            'approved_amount' => $this->approved_amount !== null ? (float) $this->approved_amount : null,
            'approved_due_date' => $this->approved_due_date?->format('Y-m-d'),
            'proposal_amount' => $this->proposal_amount !== null ? (float) $this->proposal_amount : null,
            'proposal_valid_until' => $this->proposal_valid_until?->format('Y-m-d'),
            'proposal_upload_uuid' => $this->proposal_upload_uuid,
            'proposal_sent_at' => $this->proposal_sent_at?->toISOString(),
            'accepted_at' => $this->accepted_at?->toISOString(),
            'deposit_amount' => $this->deposit_amount !== null ? (float) $this->deposit_amount : null,
            'deposit_due_date' => $this->deposit_due_date?->format('Y-m-d'),
            'deposit_paid_at' => $this->deposit_paid_at?->toISOString(),
            'balance_amount' => $this->balance_amount !== null ? (float) $this->balance_amount : null,
            'additional_charges' => $this->additional_charges !== null ? (float) $this->additional_charges : null,
            'balance_due_date' => $this->balance_due_date?->format('Y-m-d'),
            'fully_paid_at' => $this->fully_paid_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }

    public static function siteVisitLabel(?string $siteVisit): ?string
    {
        return match ($siteVisit) {
            VenueInquiry::SITE_VISIT_YES => 'Yes, visit first',
            VenueInquiry::SITE_VISIT_NO => 'No, proceed to booking',
            default => null,
        };
    }

    public static function visitScheduledLabel(?string $date, ?string $time): ?string
    {
        if (!$date) {
            return null;
        }

        if (!$time) {
            return $date;
        }

        return trim($date . ' · ' . $time);
    }

    private function formatVisitScheduledTime(): ?string
    {
        if (empty($this->visit_scheduled_time)) {
            return null;
        }

        return substr((string) $this->visit_scheduled_time, 0, 5);
    }

    private function resolveSiteVisit(): ?string
    {
        if (!empty($this->site_visit)) {
            return $this->site_visit;
        }

        $message = $this->message ?? '';

        if (str_contains($message, 'Site visit requested: Yes')) {
            return VenueInquiry::SITE_VISIT_YES;
        }

        if (str_contains($message, 'Site visit requested: No')) {
            return VenueInquiry::SITE_VISIT_NO;
        }

        return null;
    }

    private function shouldRevealContactDetails(Request $request): bool
    {
        if (auth('admin')->check()) {
            return $this->resource->merchantCanViewContactDetails();
        }

        return true;
    }
}
