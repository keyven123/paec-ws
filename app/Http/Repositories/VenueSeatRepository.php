<?php

namespace App\Http\Repositories;

use App\Exceptions\NoVenueSeatFoundException;
use App\Exceptions\UnauthorizedException;
use App\Models\VenueSeat;
use App\Helpers\GeneralHelper;
use Illuminate\Contracts\Database\Eloquent\Builder;

class VenueSeatRepository
{
    /**
     * @param VenueSeat $venueSeat
     */
    public function __construct(protected VenueSeat $venueSeat)
    {
    }

    /**
     * @param array $filters
     * @return Builder
     */
    public function getAll(?array $filters): Builder
    {
        return $this->venueSeat->with(['venue'])
            ->filters($filters)
            ->orderBy('col', 'asc')
            ->orderBy('row', 'asc')
            ->orderBy('seat_no', 'asc');
    }

    /**
     * Fetch venue seat or throw exception if not found.
     *
     * @param string $key
     * @param string $value
     * @return VenueSeat
     * @throws NoVenueSeatFoundException
     */
    public function fetchOrThrow(string $key, string $value): VenueSeat
    {
        $venueSeat = $this->venueSeat->with(['venue', 'creator', 'updater'])
            ->where($key, $value)->first();

        if (is_null($venueSeat)) {
            throw new NoVenueSeatFoundException();
        }

        return $venueSeat;
    }

    /**
     * @param array $payload
     * @return VenueSeat
     */
    public function create(array $payload): VenueSeat
    {
        $venueSeatPayload = GeneralHelper::unsetUnknownAndNullFields($payload, VenueSeat::DATA);
        return $this->venueSeat->create($venueSeatPayload);
    }

    /**
     * @param VenueSeat $venueSeat
     * @param array $payload
     * @return bool|VenueSeat
     */
    public function update(VenueSeat $venueSeat, array $payload): bool|VenueSeat
    {
        $venueSeatPayload = GeneralHelper::unsetUnknownAndNullFields($payload, VenueSeat::DATA);
        return $venueSeat->update($venueSeatPayload);
    }

    /**
     * @param VenueSeat $venueSeat
     * @return void
     * @throws UnauthorizedException
     */
    public function delete(VenueSeat $venueSeat): void
    {
        // Check if venue seat has associated ticket seats
        if ($venueSeat->ticketSeats()->exists()) {
            throw new UnauthorizedException('Cannot delete venue seat with associated ticket seats.');
        }

        $venueSeat->delete();
    }
}
