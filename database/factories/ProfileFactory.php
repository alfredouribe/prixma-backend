<?php

namespace Database\Factories;

use App\Models\Profile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Profile> */
class ProfileFactory extends Factory
{
    protected $model = Profile::class;

    public function definition(): array
    {
        return [
            'user_id'              => User::factory()->withCompletedOnboarding(),
            'display_name'         => fake()->firstName(),
            'city'                 => 'CDMX',
            'intention'            => 'friendship',
            'onboarding_step'      => 6,
            'onboarding_completed' => true,
            'verification_status'  => 'unverified',
        ];
    }

    public function pending(): static
    {
        return $this->state(['verification_status' => 'pending']);
    }

    public function verified(): static
    {
        return $this->state(['verification_status' => 'verified']);
    }
}
