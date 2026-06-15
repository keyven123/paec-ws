<?php

namespace App\Models;

use Carbon\Carbon;
use App\Models\Traits\Otpable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PasswordSetup extends Model
{
    use HasFactory;
    use Otpable;
    use HasUuids;

    public $incrementing = false;
    public $primaryKey = 'uuid';
    protected $keyType = 'string';
    public const TYPE_SETUP = 'password_setup';
    public const TYPE_RESET = 'password_reset';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'email',
        'type',
        'confirmed_at',
        'expires_at'
    ];

    protected $dates = [
        'confirmed_at',
        'expires_at',
    ];

    /**
     * This function is used to filter the query by the uuid column
     * @param Builder $query
     * @param string $value
     * @return Builder
     */
    public function scopeUuid(Builder $query, string $value): Builder
    {
        return $query->where('uuid', $value);
    }

    /**
     * Confirm OTP
     *
     * @return void
     */
    public function confirmOtp(): void
    {
        $this->confirmed_at = new Carbon();
        $this->save();
    }
}
