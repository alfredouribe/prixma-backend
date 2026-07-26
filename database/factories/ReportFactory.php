<?php

namespace Database\Factories;

use App\Models\Profile;
use App\Models\Report;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Report> */
class ReportFactory extends Factory
{
    protected $model = Report::class;

    public function definition(): array
    {
        return [
            // ->has(Profile::factory(), 'profile') crea también el Profile
            // del reporter/reported — ReportResource muestra
            // `profile.display_name` con fallback a `email`, así que las
            // pruebas de búsqueda por nombre necesitan que el perfil exista.
            'reporter_id' => User::factory()->withCompletedOnboarding()->has(Profile::factory(), 'profile'),
            'reported_id' => User::factory()->withCompletedOnboarding()->has(Profile::factory(), 'profile'),
            'reason'      => 'harassment',
            'description' => fake()->sentence(),
            'status'      => 'pending',
        ];
    }

    public function reviewed(): static
    {
        return $this->state(['status' => 'reviewed']);
    }

    public function resolved(): static
    {
        return $this->state(['status' => 'resolved']);
    }
}
