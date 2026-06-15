<?php

namespace App\Models\Concerns;

use App\Models\BlockedDate;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Default implementation of the App\Contracts\Blockable contract.
 *
 * Models using this trait expose a polymorphic `blockedDates` relation and a
 * conflict check that, by default, allows any date to be blocked. Models that
 * need custom conflict rules (e.g. events with sold tickets) should override
 * hasBlockedDateConflict().
 */
trait HasBlockedDates
{
    public function blockedDates(): MorphMany
    {
        return $this->morphMany(
            BlockedDate::class,
            'blockable',
            'blockable_type',
            'blockable_uuid',
            'uuid'
        );
    }

    public function hasBlockedDateConflict(string $date): bool
    {
        return false;
    }
}
