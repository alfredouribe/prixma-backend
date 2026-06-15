<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->id,
            'email'               => $this->email,
            'status'              => $this->status,
            'onboarding_completed' => $this->onboarding_completed,
            'email_verified_at'   => $this->email_verified_at,
            'created_at'          => $this->created_at,
        ];
    }
}
