<?php

namespace Database\Factories;

use App\Models\EventTicket;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\EventTicket>
 */
class EventTicketFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->word(),
            'name' => fake()->name(),
            'description' => fake()->text(),
            'price' => fake()->randomFloat(2, 0, 1000),
            'is_bundle' => false,
            'available_from' => Carbon::now(),
            'available_to' => Carbon::now()->addMonth(),
            'display_order' => 1,
            'max_ticket' => fake()->numberBetween(0, 100),
        ];
    }
}
