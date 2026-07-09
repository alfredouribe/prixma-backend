<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VerificationStatusResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'status'            => $this->status,
            'rejection_reason'  => $this->rejection_reason,
            'reviewed_at'       => $this->reviewed_at,
            'created_at'        => $this->created_at,
        ];
    }
}
