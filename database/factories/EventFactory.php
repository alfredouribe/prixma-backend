<?php

namespace Database\Factories;

use App\Models\Admin;
use App\Models\Event;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Event> */
class EventFactory extends Factory
{
    protected $model = Event::class;

    public function definition(): array
    {
        return [
            'creator_id'    => Admin::factory(),
            'title'         => fake()->sentence(4),
            'description'   => fake()->paragraph(),
            'category'      => fake()->randomElement(['pride', 'social', 'art', 'activism']),
            'event_date'    => fake()->dateTimeBetween('+1 day', '+2 months'),
            'location_name' => fake()->address(),
            'latitude'      => fake()->latitude(19.0, 19.6),
            'longitude'     => fake()->longitude(-99.3, -99.0),
            'external_link' => null,
            'image_url'     => null,
            'image_key'     => null,
        ];
    }

    public function category(string $category): static
    {
        return $this->state(['category' => $category]);
    }

    public function onDate(\DateTimeInterface|string $date): static
    {
        return $this->state(['event_date' => $date]);
    }

    public function withoutLocation(): static
    {
        return $this->state(['latitude' => null, 'longitude' => null]);
    }
}
