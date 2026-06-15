<?php

namespace App\Models;

use Carbon\Carbon;
use App\Models\Traits\Otpable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class PasswordReset extends Model
{
    use HasUuids;
    use HasFactory;
    use Otpable;

    public $incrementing = false;
    public $primaryKey = 'uuid';
    protected $keyType = 'string';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'uuid',
        'email',
        'resettable_id',
        'resettable_type',
        'confirmed_at',
        'expires_at',
        'created_at',
        'updated_at'
    ];

    protected $dates = [
        'confirmed_at',
        'expires_at',
        'created_at',
        'updated_at'
    ];

    /**
     * Set expiration
     *
     * @return void
     */
    public function setExpiration(): void
    {
        $this->expires_at = Carbon::now()
            ->timezone('Asia/Manila')
            ->addHour()
            ->format('Y-m-d H:i:s');
    }

    public function resettable()
    {
        return $this->morphTo();
    }

    /**
     * Refresh expiration
     *
     * @return void
     */
    public function refreshExpiration(): void
    {
        $this->setExpiration();
        $this->save();
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
