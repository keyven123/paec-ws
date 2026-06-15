<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ConfirmationToken extends Model
{
    use HasUuids;
    use HasFactory;

    public $incrementing = false;
    public $primaryKey = 'uuid';
    protected $keyType = 'string';

    protected $fillable = [
        'user_uuid',
        'token',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    /**
     * Create a new confirmation token for a user
     */
    public static function createForUser($userUuid, $ttlMinutes = 60)
    {
        // Delete any existing tokens for this user
        static::where('user_uuid', $userUuid)->delete();

        // Create new token — 6-digit numeric code (e.g. 042891)
        return static::create([
            'user_uuid' => $userUuid,
            'token' => str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT),
            'expires_at' => now()->addMinutes($ttlMinutes),
        ]);
    }

    /**
     * Get the plain token (for display in email)
     */
    public function getPlainToken()
    {
        return $this->token;
    }

    /**
     * Check if token is valid and not expired
     */
    public function isValid()
    {
        return $this->expires_at->isFuture();
    }

    /**
     * Get the user that owns this token
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_uuid', 'uuid');
    }
}