<?php

namespace App\Http\Resources;

use App\Models\VenueInquiry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VenueListingDashboardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return array_merge(
            (new VenueListingResource($this->resource))->toArray($request),
            [
                'inquiry_status_counts' => $this->inquiry_status_counts ?? [
                    'all' => 0,
                    'new' => 0,
                    'in_discussion' => 0,
                    'site_visit_scheduled' => 0,
                    'proposal_sent' => 0,
                    'accepted' => 0,
                    'deposit_requested' => 0,
                    'deposit_paid' => 0,
                    'balance_due' => 0,
                    'fully_paid' => 0,
                    'completed' => 0,
                    'cancelled' => 0,
                    'visit-schedule' => 0,
                ],
                'stats' => [
                    'inquiries_count' => $this->inquiries_count,
                    'bookings_count' => $this->confirmedBookingsCount(),
                    'rating' => (float) $this->rating,
                    'review_count' => $this->review_count,
                ],
            ]
        );
    }

    private function confirmedBookingsCount(): int
    {
        if (isset($this->confirmed_inquiries_count)) {
            return (int) $this->confirmed_inquiries_count;
        }

        return $this->inquiries()
            ->whereIn('status', [
                VenueInquiry::STATUSES['FULLY_PAID'],
                VenueInquiry::STATUSES['COMPLETED'],
            ])
            ->count();
    }
}
