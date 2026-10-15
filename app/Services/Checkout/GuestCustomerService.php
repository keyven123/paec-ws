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
     * A returning guest takes the details from their latest checkout, since tickets and emails are
     * issued in that name. Registered accounts are reused as-is so their profile is never overwritten.
     *
     * @param  array{first_name: string, last_name: string, address: string, email: string, phone_number: string}  $details
     */
    public static function resolve(array $details): User
    {
        $email = Str::lower(trim($details['email']));

        $existing = User::query()->where('email', $email)->first();
        if ($existing && $existing->provider !== User::GUEST_PROVIDER) {
            return $existing;
        }

        $phoneTaken = User::query()
            ->where('phone_number', $details['phone_number'])
            ->when($existing, fn ($query) => $query->where('uuid', '!=', $existing->uuid))
            ->exists();

        $attributes = [
            'first_name' => trim($details['first_name']),
            'last_name' => trim($details['last_name']),
            'address_line_1' => trim($details['address']),
            'phone_number' => $phoneTaken ? $existing?->phone_number : $details['phone_number'],
        ];

        if ($existing) {
            $existing->update($attributes);

            return $existing;
        }

        return self::createGuest([...$attributes, 'email' => $email]);
    }

    /**
     * Find the customer for an email, creating a guest customer named after the email's local part when none exists.
     */
    public static function resolveByEmail(string $email): User
    {
        $email = Str::lower(trim($email));

        $existing = User::query()->where('email', $email)->first();
        if ($existing) {
            return $existing;
        }

        $name = Str::of(Str::before($email, '@'))->replaceMatches('/[._\-+]+/', ' ')->trim()->title()->value();

        return self::createGuest([
            'first_name' => $name !== '' ? $name : 'Guest',
            'last_name' => '',
            'email' => $email,
        ]);
    }

    private static function createGuest(array $attributes): User
    {
        $role = Role::whereCode(GeneralConstants::ROLES['CUSTOMER']['name'])->first();

        return User::create([
            'role_uuid' => $role?->uuid,
            ...$attributes,
            'password' => Str::random(40),
            'provider' => User::GUEST_PROVIDER,
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'marketing_consent' => false,
            'is_first_time_login' => false,
        ]);
    }
}
