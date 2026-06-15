<?php

namespace App\Services;

use App\Http\Repositories\BlockedDateRepository;
use App\Models\VenueInquiry;
use App\Models\VenueListing;
use Carbon\Carbon;

class VenueListingAvailabilityService
{
    public function __construct(
        protected BlockedDateRepository $blockedDateRepository,
    ) {
    }

    /**
     * Dates that customers cannot select when submitting a new inquiry.
     *
     * @return list<array{uuid: ?string, blocked_date: string, reason: string}>
     */
    public function publicUnavailableDates(VenueListing $venue): array
    {
        $blocked = $this->blockedDateRepository->getAll([
            'blockable_type' => $venue->getMorphClass(),
            'blockable_uuid' => $venue->getKey(),
        ])->get();

        $data = [];
        $seen = [];

        foreach ($blocked as $record) {
            $date = $record->blocked_date?->toDateString();
            if ($date === null || isset($seen[$date])) {
                continue;
            }
            $seen[$date] = true;
            $data[] = [
                'uuid' => $record->uuid,
                'blocked_date' => $date,
                'reason' => $record->reason ?? 'Closed',
            ];
        }

        $softHoldDates = VenueInquiry::query()
            ->where('venue_listing_uuid', $venue->getKey())
            ->whereIn('status', VenueInquiry::SOFT_HOLD_STATUSES)
            ->whereNotNull('event_date')
            ->pluck('event_date');

        foreach ($softHoldDates as $eventDate) {
            $date = $this->normalizeDate($eventDate);
            if ($date === null || isset($seen[$date])) {
                continue;
            }
            $seen[$date] = true;
            $data[] = [
                'uuid' => null,
                'blocked_date' => $date,
                'reason' => 'Reserved',
            ];
        }

        $bookedDates = VenueInquiry::query()
            ->where('venue_listing_uuid', $venue->getKey())
            ->whereIn('status', VenueInquiry::HARD_BLOCK_STATUSES)
            ->whereNotNull('event_date')
            ->pluck('event_date');

        foreach ($bookedDates as $eventDate) {
            $date = $this->normalizeDate($eventDate);
            if ($date === null || isset($seen[$date])) {
                continue;
            }
            $seen[$date] = true;
            $data[] = [
                'uuid' => null,
                'blocked_date' => $date,
                'reason' => 'Booked',
            ];
        }

        return $data;
    }

    public function isDateUnavailable(VenueListing $venue, string $date): bool
    {
        $normalized = Carbon::parse($date)->toDateString();

        $manualBlock = $this->blockedDateRepository->getAll([
            'blockable_type' => $venue->getMorphClass(),
            'blockable_uuid' => $venue->getKey(),
        ])->whereDate('blocked_date', $normalized)->exists();

        if ($manualBlock) {
            return true;
        }

        $softHold = VenueInquiry::query()
            ->where('venue_listing_uuid', $venue->getKey())
            ->whereIn('status', VenueInquiry::SOFT_HOLD_STATUSES)
            ->whereDate('event_date', $normalized)
            ->exists();

        if ($softHold) {
            return true;
        }

        return VenueInquiry::query()
            ->where('venue_listing_uuid', $venue->getKey())
            ->whereIn('status', VenueInquiry::HARD_BLOCK_STATUSES)
            ->whereDate('event_date', $normalized)
            ->exists();
    }

    private function normalizeDate(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof Carbon) {
            return $value->toDateString();
        }

        return Carbon::parse((string) $value)->toDateString();
    }
}
