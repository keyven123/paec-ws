<?php

namespace App\Http\Repositories;

use App\Exceptions\NoBlockedDateFoundException;
use App\Helpers\GeneralHelper;
use App\Models\BlockedDate;
use Illuminate\Database\Eloquent\Builder;

class BlockedDateRepository
{
    public function __construct(protected BlockedDate $blockedDate)
    {
    }

    public function getAll(array $filters): Builder
    {
        return $this->blockedDate
            ->filters($filters)
            ->orderBy('blocked_date', 'asc')
            ->orderBy('created_at', 'desc');
    }

    /**
     * @throws NoBlockedDateFoundException
     */
    public function fetchOrThrow(string $key, string $value): BlockedDate
    {
        $record = $this->blockedDate->where($key, $value)->first();
        if (is_null($record)) {
            throw new NoBlockedDateFoundException();
        }
        return $record;
    }

    public function create(array $payload): BlockedDate
    {
        $clean = GeneralHelper::unsetUnknownAndNullFields($payload, BlockedDate::DATA);
        return $this->blockedDate->create($clean);
    }

    public function findSoftDeleted(string $blockableType, string $blockableUuid, string $blockedDate): ?BlockedDate
    {
        return $this->blockedDate
            ->withTrashed()
            ->where('blockable_type', $blockableType)
            ->where('blockable_uuid', $blockableUuid)
            ->whereDate('blocked_date', $blockedDate)
            ->whereNotNull('deleted_at')
            ->first();
    }

    public function restore(BlockedDate $blockedDate): bool
    {
        return $blockedDate->restore();
    }

    public function update(BlockedDate $blockedDate, array $payload): bool
    {
        $clean = GeneralHelper::unsetUnknownAndNullFields($payload, BlockedDate::DATA);
        return $blockedDate->update($clean);
    }

    public function delete(BlockedDate $blockedDate): void
    {
        $blockedDate->delete();
    }
}
