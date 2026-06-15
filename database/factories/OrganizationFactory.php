<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\OrganizationBank;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_type' => Organization::BUSINESS_TYPE_SOLE_PROPRIETORSHIP,
            'name' => fake()->company(),
            'representative_first_name' => fake()->firstName(),
            'representative_last_name' => fake()->lastName(),
            'address' => fake()->address(),
            'contact_number' => fake()->phoneNumber(),
            'email' => fake()->unique()->companyEmail(),
            'description' => fake()->text(),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Organization $organization) {
            OrganizationBank::factory()->create([
                'organization_uuid' => $organization->uuid,
                'is_default' => true,
            ]);
        });
    }
}
