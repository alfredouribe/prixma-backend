<?php

namespace Database\Factories;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Notification> */
class NotificationFactory extends Factory
{
    protected $model = Notification::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory()->withCompletedOnboarding(),
            'type' => 'match',
            'title' => '¡Es un match! 🌟',
            'body' => fake()->sentence(),
            'data' => null,
            'read_at' => null,
            'sent_at' => null,
        ];
    }

    public function read(): static
    {
        return $this->state(['read_at' => now()]);
    }

    public function message(): static
    {
        return $this->state([
            'type' => 'message',
            'title' => fake()->firstName(),
            'body' => fake()->realText(50),
        ]);
    }

    public function superLike(): static
    {
        return $this->state([
            'type' => 'super_like',
            'title' => 'A alguien le encantaste ⭐',
            'body' => 'Explora para descubrir quién.',
        ]);
    }

    public function requestAccepted(): static
    {
        return $this->state([
            'type' => 'request_accepted',
            'body' => 'Ahora pueden chatear libremente.',
        ]);
    }
}
