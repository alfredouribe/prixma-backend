<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'email'               => fake()->unique()->safeEmail(),
            'password'            => Hash::make('password'),
            'status'              => 'active',
            'date_of_birth'       => fake()->dateTimeBetween('-40 years', '-18 years')->format('Y-m-d'),
            'terms_accepted_at'   => now(),
            'privacy_accepted_at' => now(),
            'email_verified_at'   => now(),
            'onboarding_completed' => false,
        ];
    }

    public function unverified(): static
    {
        return $this->state(['email_verified_at' => null]);
    }

    public function suspended(): static
    {
        return $this->state(['status' => 'suspended']);
    }

    public function banned(): static
    {
        return $this->state(['status' => 'banned']);
    }

    public function withCompletedOnboarding(): static
    {
        return $this->state(['onboarding_completed' => true]);
    }
}
