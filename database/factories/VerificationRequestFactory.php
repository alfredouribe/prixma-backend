<?php

namespace Database\Factories;

use App\Models\Profile;
use App\Models\VerificationRequest;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<VerificationRequest> */
class VerificationRequestFactory extends Factory
{
    protected $model = VerificationRequest::class;

    public function definition(): array
    {
        return [
            'profile_id'      => Profile::factory(),
            'document_s3_key' => 'verification/' . Str::uuid() . '/document.jpg',
            'selfie_s3_key'   => null,
            'status'          => 'pending',
        ];
    }

    public function approved(): static
    {
        return $this->state(['status' => 'approved']);
    }

    public function rejected(): static
    {
        return $this->state([
            'status'            => 'rejected',
            'rejection_reason'  => 'La foto no es legible.',
        ]);
    }
}
