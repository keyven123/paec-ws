<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\VenueListing;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<VenueListing>
 */
class VenueListingFactory extends Factory
{
    protected $model = VenueListing::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->company() . ' Function Hall';

        return [
            'organization_uuid' => Organization::factory(),
            'slug' => Str::slug($name) . '-' . fake()->unique()->numerify('###'),
            'name' => $name,
            'description' => fake()->paragraph(),
            'address' => fake()->streetAddress() . ', Makati City',
            'location' => fake()->streetName(),
            'city' => 'Makati City',
            'region' => 'Metro Manila',
            'area' => fake()->numberBetween(200, 900) . ' sqm',
            'capacity_label' => '50–500 pax',
            'capacity_min' => 50,
            'capacity_max' => 500,
            'venue_type' => 'Function hall',
            'category' => VenueListing::CATEGORIES['FUNCTION_HALLS'],
            'price_per_event' => fake()->numberBetween(20000, 100000),
            'currency' => 'PHP',
            'status' => VenueListing::STATUSES['DRAFT'],
            'is_featured' => false,
            'rating' => fake()->randomFloat(1, 4, 5),
            'review_count' => fake()->numberBetween(0, 50),
            'image_color' => '#1e3a5f',
            'verified' => true,
            'responds_in' => '24 hrs',
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => [
            'status' => VenueListing::STATUSES['PUBLISHED'],
            'is_featured' => true,
            'badge' => 'Featured',
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'status' => VenueListing::STATUSES['APPROVED'],
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn () => [
            'status' => VenueListing::STATUSES['PENDING'],
        ]);
    }

    public function conference(): static
    {
        return $this->state(fn () => [
            'venue_type' => 'Conference',
            'category' => VenueListing::CATEGORIES['CONFERENCE'],
        ]);
    }
}
