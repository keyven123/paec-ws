<?php

namespace App\Http\Repositories;

use App\Constants\GeneralConstants;
use App\Exceptions\NoResourceFoundException;
use App\Exceptions\UnauthorizedException;
use App\Models\Place;
use App\Models\Venue;
use App\Helpers\GeneralHelper;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class VenueRepository
{
    /**
     * @param Venue $venue
     * @param Place $place
     */
    public function __construct(protected Venue $venue, protected Place $place)
    {
    }

    /**
     * @param array $filters
     * @return Builder
     */
    public function getAll(array $filters): Builder
    {
        return $this->venue->filters($filters)
            ->orderBy('created_at', 'desc');
    }

    public function getPublicPlaces(array $filters): Builder
    {
        return $this->place->filters($filters)
            ->where('status', GeneralConstants::GENERAL_STATUSES['ACTIVE'])
            ->orderBy('created_at', 'desc');
    }

    /**
     * Fetch venue or throw exception if not found.
     *
     * @param string $key
     * @param string $value
     * @return Venue
     * @throws NoResourceFoundException
     */
    public function fetchOrThrow(string $key, string $value): Venue
    {
        $venue = $this->venue->where($key, $value)->first();

        if (is_null($venue)) {
            throw new NoResourceFoundException();
        }

        return $venue;
    }

    /**
     * @param array $payload
     * @return Venue
     */
    public function create(array $payload): Venue
    {
        $venuePayload = GeneralHelper::unsetUnknownAndNullFields($payload, Venue::DATA);
        $venuePayload['code'] = Str::slug($venuePayload['name']);
        return $this->venue->create($venuePayload);
    }

    /**
     * @param Venue $venue
     * @param array $payload
     * @return bool|Venue
     */
    public function update(Venue $venue, array $payload): bool|Venue
    {
        $venuePayload = GeneralHelper::unsetUnknownAndNullFields($payload, Venue::DATA);
        $venuePayload['code'] = Str::slug($venuePayload['name']);
        return $venue->update($venuePayload);
    }

    /**
     * @param Venue $venue
     * @return void
     * @throws UnauthorizedException
     */
    public function delete(Venue $venue): void
    {
        // Add any business logic for deletion here
        // For example, prevent deletion if venue is in use
        if ($venue->events()->count() > 0) {
            throw new UnauthorizedException('Cannot delete venue that is being used by events.');
        }

        $venue->delete();
    }
}
