<?php

namespace App\Http\Repositories;

use App\Exceptions\NoVenueInquiryFoundException;
use App\Models\VenueInquiry;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class VenueInquiryRepository
{
    public function __construct(protected VenueInquiry $venueInquiry)
    {
    }

    public function getMyInquiries(string $userUuid, string $email, array $filters = []): Builder
    {
        return $this->venueInquiry
            ->with(['venueListing:uuid,slug,name,city,venue_type,image_color'])
            ->where(function (Builder $query) use ($userUuid, $email) {
                $query->where('user_uuid', $userUuid)
                    ->orWhere('email', $email);
            })
            ->orderByDesc('created_at');
    }

    public function paginateForVenue(string $venueListingUuid, array $filters, int $perPage = 10): LengthAwarePaginator
    {
        $query = $this->baseQueryForVenue($venueListingUuid);
        $query = $this->applyStatusTabFilter($query, $filters['status'] ?? null);
        $query = $this->applySearchFilter($query, $filters['q'] ?? null);

        return $query
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    /**
     * @return array<string, int>
     */
    public function statusCountsForVenue(string $venueListingUuid): array
    {
        $base = $this->baseQueryForVenue($venueListingUuid);

        return [
            'all' => (clone $base)->count(),
            'new' => (clone $base)->where('status', VenueInquiry::STATUSES['NEW'])->count(),
            'in_discussion' => (clone $base)->where('status', VenueInquiry::STATUSES['IN_DISCUSSION'])->count(),
            'site_visit_scheduled' => (clone $base)->where('status', VenueInquiry::STATUSES['SITE_VISIT_SCHEDULED'])->count(),
            'proposal_sent' => (clone $base)->where('status', VenueInquiry::STATUSES['PROPOSAL_SENT'])->count(),
            'accepted' => (clone $base)->where('status', VenueInquiry::STATUSES['ACCEPTED'])->count(),
            'deposit_requested' => (clone $base)->where('status', VenueInquiry::STATUSES['DEPOSIT_REQUESTED'])->count(),
            'deposit_paid' => (clone $base)->where('status', VenueInquiry::STATUSES['DEPOSIT_PAID'])->count(),
            'balance_due' => (clone $base)->where('status', VenueInquiry::STATUSES['BALANCE_DUE'])->count(),
            'fully_paid' => (clone $base)->where('status', VenueInquiry::STATUSES['FULLY_PAID'])->count(),
            'completed' => (clone $base)->where('status', VenueInquiry::STATUSES['COMPLETED'])->count(),
            'cancelled' => (clone $base)->where('status', VenueInquiry::STATUSES['CANCELLED'])->count(),
            'visit-schedule' => $this->applyVisitScheduleFilter(clone $base)->count(),
        ];
    }

    public function fetchOrThrow(string $uuid): VenueInquiry
    {
        $inquiry = $this->venueInquiry->where('uuid', $uuid)->first();

        if (is_null($inquiry)) {
            throw new NoVenueInquiryFoundException();
        }

        return $inquiry;
    }

    public function update(VenueInquiry $inquiry, array $payload): VenueInquiry
    {
        $inquiry->update($payload);

        return $inquiry->fresh();
    }

    private function baseQueryForVenue(string $venueListingUuid): Builder
    {
        return $this->venueInquiry->newQuery()
            ->where('venue_listing_uuid', $venueListingUuid);
    }

    private function applyStatusTabFilter(Builder $query, ?string $statusTab): Builder
    {
        if ($statusTab === null || $statusTab === '' || $statusTab === 'all') {
            return $query;
        }

        if ($statusTab === 'visit-schedule') {
            return $this->applyVisitScheduleFilter($query);
        }

        return $query->where('status', $statusTab);
    }

    private function applyVisitScheduleFilter(Builder $query): Builder
    {
        return $query->where(function (Builder $builder) {
            $builder->where('status', VenueInquiry::STATUSES['SITE_VISIT_SCHEDULED'])
                ->orWhere(function (Builder $nested) {
                    $nested->where('site_visit', VenueInquiry::SITE_VISIT_YES)
                        ->whereNotNull('visit_scheduled_date');
                });
        });
    }

    private function applySearchFilter(Builder $query, ?string $search): Builder
    {
        $search = trim((string) $search);
        if ($search === '') {
            return $query;
        }

        $keyword = '%' . $search . '%';

        return $query->where(function (Builder $builder) use ($keyword) {
            $builder->where('full_name', 'like', $keyword)
                ->orWhere('email', 'like', $keyword)
                ->orWhere('message', 'like', $keyword);
        });
    }
}
