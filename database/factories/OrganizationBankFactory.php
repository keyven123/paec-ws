<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\OrganizationBank;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrganizationBank>
 */
class OrganizationBankFactory extends Factory
{
    protected $model = OrganizationBank::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_uuid' => Organization::factory(),
            'account_type' => OrganizationBank::ACCOUNT_TYPE_SAVINGS,
            'bank_name' => fake()->company(),
            'bank_branch' => fake()->city(),
            'bank_address' => fake()->address(),
            'bank_account_name' => fake()->name(),
            'bank_account_number' => fake()->bankAccountNumber(),
            'is_default' => true,
            'status' => OrganizationBank::STATUS_ACTIVE,
        ];
    }
}
