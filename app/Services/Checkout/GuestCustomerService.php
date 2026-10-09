<?php

namespace App\Services\Checkout;

use App\Constants\GeneralConstants;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;

class GuestCustomerService
{
    /**
     * Find the customer for a guest checkout by email, creating a guest customer when none exists.
     * Existing accounts are reused as-is so their profile is never overwritten by guest input.
     *
     * @param  array{first_name: string, last_name: string, address: string, email: string, phone_number: string}  $details
     */
    public static function resolve(array $details): User
    {
        $email = Str::lower(trim($details['email']));

        $existing = User::query()->where('email', $email)->first();
        if ($existing) {
            return $existing;
        }

        $phoneTaken = User::query()->where('phone_number', $details['phone_number'])->exists();
        $role = Role::whereCode(GeneralConstants::ROLES['CUSTOMER']['name'])->first();

        return User::create([
            'role_uuid' => $role?->uuid,
            'first_name' => trim($details['first_name']),
            'last_name' => trim($details['last_name']),
            'address_line_1' => trim($details['address']),
            'email' => $email,
            'phone_number' => $phoneTaken ? null : $details['phone_number'],
            'password' => Str::random(40),
            'provider' => User::GUEST_PROVIDER,
            'marketing_consent' => false,
            'is_first_time_login' => false,
        ]);
    }
}
