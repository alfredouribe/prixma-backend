<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\EventRsvp;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EventRsvp> */
class EventRsvpFactory extends Factory
{
    protected $model = EventRsvp::class;

    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'user_id'  => User::factory()->withCompletedOnboarding(),
            'status'   => fake()->randomElement(['interested', 'going', 'not_going']),
        ];
    }

    public function interested(): static
    {
        return $this->state(['status' => 'interested']);
    }

    public function going(): static
    {
        return $this->state(['status' => 'going']);
    }

    public function notGoing(): static
    {
        return $this->state(['status' => 'not_going']);
    }
}
