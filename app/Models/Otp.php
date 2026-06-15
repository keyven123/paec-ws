<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Otp extends Model
{
    use HasFactory;
    use Notifiable;
    use HasUuids;

    public $incrementing = false;
    public $primaryKey = 'uuid';
    protected $keyType = 'string';
    const RESOURCE_KEY = 'otps';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'uuid',
        'secret',
        'receiver',
        'expires_at',
        'otpable_type',
        'otpable_id'
    ];

    protected $dates = [
        'resendable_at',
        'expires_at',
    ];

    /**
     * Route notifications for the mail channel.
     * @param  \Illuminate\Notifications\Notification  $notification
     * @return array|string
     */
    public function routeNotificationForMail($notification)
    {
        return $this->receiver;
    }

    /**
     * @return MorphTo
     */
    public function otpable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return bool
     */
    public function isExpired(): bool
    {
        return (new Carbon())->gte($this->expires_at);
    }

    /**
     * @param string $value
     * @return void
     */
    public function setSecretAttribute(string $value): void
    {
        $this->attributes['secret'] = Hash::make($value);
    }
}
