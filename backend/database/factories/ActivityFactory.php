<?php

namespace Database\Factories;

use App\Models\Activity;
use App\Models\District;
use App\Models\Sport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Activity>
 */
class ActivityFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsAt = now()->addDays(fake()->numberBetween(1, 30))->setSeconds(0);

        return [
            'host_id' => User::factory(),
            'sport_id' => Sport::factory(),
            'district_id' => District::factory(),
            'title' => fake()->sentence(3),
            'description' => fake()->optional()->paragraph(),
            'location' => fake()->streetAddress(),
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addHours(2),
            'capacity' => fake()->numberBetween(4, 20),
            'joined_count' => 0,
        ];
    }

    /**
     * An activity with exactly $seats seats, none of them taken.
     */
    public function withCapacity(int $seats): static
    {
        return $this->state(fn () => ['capacity' => $seats]);
    }

    /**
     * An activity that has already started, so it no longer takes
     * registrations.
     */
    public function started(): static
    {
        return $this->state(fn () => [
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
        ]);
    }
}
