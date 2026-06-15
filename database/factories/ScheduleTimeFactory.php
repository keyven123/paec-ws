<?php

namespace Database\Factories;

use App\Constants\GeneralConstants;
use App\Models\Schedule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ScheduleTime>
 */
class ScheduleTimeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'time_start' => fake()->time(),
            'time_end' => fake()->time(),
        ];
    }
}
