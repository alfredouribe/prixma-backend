<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OnboardingStatusResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'current_step' => $this->resource['current_step'],
            'completed'    => $this->resource['completed'],
            'profile'      => $this->resource['profile']
                ? new ProfileResource($this->resource['profile'])
                : null,
        ];
    }
}
