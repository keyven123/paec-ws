<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Event;
use App\Models\EventSection;
use App\Models\Organization;
use App\Models\Venue;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Event>
 */
class EventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $venue = Venue::all()->pluck('uuid')->toArray();
        $category = Category::all()->pluck('uuid')->toArray();
        $event_section = EventSection::all()->pluck('uuid')->toArray();
        return [
            'organization_uuid' => Organization::factory()->create()->uuid,
            'venue_uuid' => fake()->randomElement($venue),
            'category_uuid' => fake()->randomElement($category),
            'event_section_uuid' => fake()->randomElement($event_section),
            'event_name' => fake()->name(),
            'event_description' => fake()->text(),
            'contact_email' => fake()->email(),
            'logo_uuid' => fake()->uuid(),
            'portrait_image_uuid' => fake()->uuid(),
            'featured_image_uuid' => fake()->uuid(),
            'event_showcase' => [fake()->url()],
            'event_config' => fake()->randomElement(Event::EVENT_CONFIGS),
            'event_type' => fake()->randomElement(Event::EVENT_TYPES),
            'schedule_type' => fake()->randomElement(Event::SCHEDULE_TYPES),
            'excluded_dates' => fake()->dateTime(),
            'published_at' => fake()->dateTime(),
            'registration_count' => fake()->numberBetween(0, 100),
            'is_featured' => fake()->boolean(),
            'featured_order' => fake()->numberBetween(0, 100),
            'featured_from' => Carbon::now()->subMonth(),
            'featured_until' => Carbon::now()->addMonth(),
            'meta_title' => fake()->name(),
            'meta_description' => fake()->text(),
            'tags' => fake()->name(),
            'track_event_meta' => fake()->boolean(),
            'meta_pixel_id' => fake()->uuid(),
            'meta_pixel_key' => fake()->text(),
            'meta_test_event_code' => fake()->text(),
        ];
    }
}
