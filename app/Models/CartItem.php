<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One activity in a cart: its tickets for a single visit date. `details` is the storefront's
 * display snapshot (names, image, prices shown); checkout always re-prices from the event.
 */
class CartItem extends Model
{
    use HasUuids;

    public $incrementing = false;
    public $primaryKey = 'uuid';
    protected $keyType = 'string';

    protected $fillable = [
        'cart_uuid',
        'event_uuid',
        'event_location_uuid',
        'visit_date',
        'visit_time',
        'promo_code_uuid',
        'tickets',
        'details',
    ];

    protected $casts = [
        'visit_date' => 'date:Y-m-d',
        'tickets' => 'array',
        'details' => 'array',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event_uuid', 'uuid');
    }

    public function eventLocation(): BelongsTo
    {
        return $this->belongsTo(EventLocation::class, 'event_location_uuid', 'uuid');
    }
}
