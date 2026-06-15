<?php

namespace Database\Factories;

use App\Models\VenueInquiry;
use App\Models\VenueListing;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VenueInquiry>
 */
class VenueInquiryFactory extends Factory
{
    protected $model = VenueInquiry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'venue_listing_uuid' => VenueListing::factory()->published(),
            'full_name' => fake()->name(),
            'email' => fake()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'event_type' => 'Corporate event',
            'event_date' => now()->addMonth()->toDateString(),
            'guest_count' => fake()->numberBetween(50, 200),
            'site_visit' => VenueInquiry::SITE_VISIT_YES,
            'message' => fake()->sentence(),
            'status' => VenueInquiry::STATUSES['NEW'],
        ];
    }
}
