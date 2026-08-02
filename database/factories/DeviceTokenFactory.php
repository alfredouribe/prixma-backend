<?php

namespace Database\Factories;

use App\Models\DeviceToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DeviceToken> */
class DeviceTokenFactory extends Factory
{
    protected $model = DeviceToken::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory()->withCompletedOnboarding(),
            'token' => fake()->unique()->uuid(),
            'platform' => fake()->randomElement(['ios', 'android']),
        ];
    }

    public function android(): static
    {
        return $this->state(['platform' => 'android']);
    }

    public function ios(): static
    {
        return $this->state(['platform' => 'ios']);
    }
}
