<?php

namespace App\Contracts;

use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Implemented by any model that can have blocked dates attached to it
 * through the polymorphic blocked_dates table.
 */
interface Blockable
{
    /**
     * The blocked dates that belong to this model.
     */
    public function blockedDates(): MorphMany;

    /**
     * Determine whether the given date cannot be blocked because the model
     * has scheduling conflicts on that date (e.g. tickets already sold).
     *
     * @param string $date Y-m-d formatted date
     */
    public function hasBlockedDateConflict(string $date): bool;
}
