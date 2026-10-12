<?php

namespace Tests\Feature\Coverage\RVenueCheckout;

use App\Constants\GeneralConstants;
use App\Models\Role;
use App\Models\User;

trait CreatesVenueCustomers
{
    private ?Role $venueCustomerRole = null;

    protected function createVenueCustomer(array $overrides = []): User
    {
        $this->venueCustomerRole ??= Role::firstOrCreate(
            ['code' => GeneralConstants::ROLES['CUSTOMER']['name']],
            ['name' => 'Customer'],
        );

        return User::factory()->create(array_merge([
            'role_uuid' => $this->venueCustomerRole->uuid,
        ], $overrides));
    }
}
