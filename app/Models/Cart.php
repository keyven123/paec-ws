<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A signed-in customer's activity cart, paid in one checkout.
 */
class Cart extends Model
{
    use HasUuids;

    public $incrementing = false;
    public $primaryKey = 'uuid';
    protected $keyType = 'string';

    protected $fillable = [
        'user_uuid',
        'last_changed_at',
        'reminder_sent_at',
    ];

    protected $casts = [
        'last_changed_at' => 'datetime',
        'reminder_sent_at' => 'datetime',
    ];

    public static function forUser(User $user): self
    {
        return self::query()->firstOrCreate(['user_uuid' => $user->uuid]);
    }

    /**
     * Any change restarts the wait before the "your cart is waiting" reminder.
     */
    public function touchChanged(): void
    {
        $this->update(['last_changed_at' => now(), 'reminder_sent_at' => null]);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_uuid', 'uuid');
    }

    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class, 'cart_uuid', 'uuid')->orderBy('created_at')->orderBy('uuid');
    }
}
