<?php

namespace App\Contracts\Repositories;

use App\Models\PasswordReset;

interface PasswordResetContract
{
    /**
     * @param array $attributes
     * @return PasswordReset|null Null when no matching active account (caller should still respond generically).
     */
    public function initiate(array $attributes): ?PasswordReset;

    /**
     * @param PasswordReset $passwordReset
     * @param array $password
     * @return bool
     */
    public function changePassword(PasswordReset $passwordReset, array $password): bool;
}
