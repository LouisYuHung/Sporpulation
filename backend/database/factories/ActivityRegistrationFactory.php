<?php

namespace Database\Factories;

use App\Enums\RegistrationStatus;
use App\Models\Activity;
use App\Models\ActivityRegistration;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActivityRegistration>
 */
class ActivityRegistrationFactory extends Factory
{
    /**
     * Note this does not touch activities.joined_count - use Activity::join()
     * when the seat count matters.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'activity_id' => Activity::factory(),
            'user_id' => User::factory(),
            'status' => RegistrationStatus::Confirmed,
            'joined_at' => now(),
        ];
    }

    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status' => RegistrationStatus::Cancelled,
            'cancelled_at' => now(),
        ]);
    }
}
