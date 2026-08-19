<?php

namespace Database\Factories;

use App\Models\Package;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Package> */
class PackageFactory extends Factory
{
    protected $model = Package::class;

    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'price' => fake()->randomFloat(2, 0, 200),
            'grant_type' => 'premium_days',
            'grant_value' => fake()->numberBetween(1, 30),
            'is_active' => true,
        ];
    }

    public function premiumDays(int $days = 7): static
    {
        return $this->state(['grant_type' => 'premium_days', 'grant_value' => $days]);
    }

    public function boostMinutes(int $minutes = 30): static
    {
        return $this->state(['grant_type' => 'boost_minutes', 'grant_value' => $minutes]);
    }

    public function seeLikersDays(int $days = 7): static
    {
        return $this->state(['grant_type' => 'see_likers_days', 'grant_value' => $days]);
    }

    public function rewindCredits(int $credits = 3): static
    {
        return $this->state(['grant_type' => 'rewind_credits', 'grant_value' => $credits]);
    }

    public function superLikeCredits(int $credits = 5): static
    {
        return $this->state(['grant_type' => 'super_like_credits', 'grant_value' => $credits]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
