<?php

namespace App\Http\Repositories;

use App\Models\PasswordSetup;
use App\Contracts\Repositories\PasswordSetupContract;
use App\Models\AdminUser;
use App\Models\Otp;
use App\Models\User;
use Carbon\Carbon;

class PasswordSetupRepository implements PasswordSetupContract
{
    protected $passwordSetup;

    /**
     * @param PasswordSetup $passwordSetup
     */
    public function __construct(PasswordSetup $passwordSetup)
    {
        $this->passwordSetup = $passwordSetup;
    }

    public function emailVerified(Otp $otp)
    {
        User::whereEmail($otp->receiver)->update(['email_verified_at' => Carbon::now()]);
    }

    /**
     * Process password setup - update password for AdminUser based on PasswordSetup email
     *
     * @param PasswordSetup $passwordSetup
     * @param string $password
     * @return array Returns ['success' => bool, 'user_type' => string, 'is_admin' => bool|null]
     */
    public function processPasswordSetup(PasswordSetup $passwordSetup, string $password): array
    {
        // Find AdminUser by email from PasswordSetup
        $adminUser = AdminUser::where('email', $passwordSetup->email)->first();

        if (!$adminUser) {
            // If AdminUser not found, try User
            $user = User::where('email', $passwordSetup->email)->first();
            if ($user) {
                $user->password = $password;
                $user->save();
                $passwordSetup->delete();

                return [
                    'success' => true,
                    'user_type' => 'user',
                    'is_admin' => null
                ];
            }
            return [
                'success' => false,
                'user_type' => null,
                'is_admin' => null
            ];
        }

        $adminUser->password = $password;
        $adminUser->is_first_time_login = false;
        if (!$adminUser->email_verified_at) {
            $adminUser->email_verified_at = Carbon::now();
        }
        $adminUser->save();

        $role = $adminUser->role;
        $passwordSetup->delete();

        return [
            'success' => true,
            'user_type' => 'admin',
            'is_admin' => $role ? $role->is_admin : false
        ];
    }
}
