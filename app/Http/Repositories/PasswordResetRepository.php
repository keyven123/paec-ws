<?php

namespace App\Http\Repositories;

use App\Models\AdminUser;
use App\Models\PasswordReset;
use App\Models\User;
use App\Events\PasswordResetExpirationWasRefreshed;
use App\Contracts\Repositories\PasswordResetContract;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class PasswordResetRepository implements PasswordResetContract
{
    protected $passwordReset;
    /**
     * @param PasswordReset $passwordReset
     */
    public function __construct(PasswordReset $passwordReset)
    {
        $this->passwordReset = $passwordReset;
    }

    /**
     * {@inheritDoc}
     */
    public function initiate(array $attributes): ?PasswordReset
    {
        $user = User::query()
            ->whereNull('deleted_at')
            ->whereRaw('LOWER(email) = ?', [strtolower(trim($attributes['email']))])
            ->first();

        if ($user === null || $user->status === 'inactive') {
            return null;
        }
        $passwordReset = $this->passwordReset->firstOrCreate(
            [
                'email' => $attributes['email'],
                'resettable_id' => $user->uuid,
                'resettable_type' => $user::class,
            ],
            [
                'email' => $attributes['email'],
                'token' => Hash::make($attributes['email']),
            ]
        );

        if (false === $passwordReset->wasRecentlyCreated) {
            $passwordReset->refreshExpiration();
            event(new PasswordResetExpirationWasRefreshed($passwordReset));
        }

        // Always send OTP for password reset requests
        $passwordReset->sendOtp();

        return $passwordReset->fresh();
    }

    public function initiateAdmin(array $attributes): ?PasswordReset
    {
        $user = AdminUser::query()
            ->whereNull('deleted_at')
            ->whereRaw('LOWER(email) = ?', [strtolower(trim($attributes['email']))])
            ->first();

        if ($user === null || $user->status === 'inactive') {
            return null;
        }
        $passwordReset = $this->passwordReset->firstOrCreate(
            [
                'email' => $attributes['email'],
                'resettable_id' => $user->uuid,
                'resettable_type' => $user::class,
            ],
            [
                'email' => $attributes['email'],
                'token' => Hash::make($attributes['email']),
            ]
        );

        if (false === $passwordReset->wasRecentlyCreated) {
            $passwordReset->refreshExpiration();
            event(new PasswordResetExpirationWasRefreshed($passwordReset));
        }

        // Always send OTP for admin password reset requests
        $passwordReset->sendOtp();

        return $passwordReset->fresh();
    }

    /**
     * {@inheritDoc}
     */
    public function changePassword(PasswordReset $passwordReset, array $payload): bool
    {
        if ($payload['user_type'] == 'admin') {
            $user = AdminUser::where('email', $passwordReset->email)->first();
        } else {
            $user = User::where('email', $passwordReset->email)->first();
        }
        $user->password = $payload['password'];
        if (!$user->email_verified_at) {
            $user->email_verified_at = Carbon::now();
        }
        // $user->is_first_time_login = 1;
        $user->is_migrated = false;
        $user->save();

        return $passwordReset->delete();
    }
}
