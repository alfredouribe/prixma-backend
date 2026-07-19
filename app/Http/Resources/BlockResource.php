<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BlockResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $blockedUser = $this->blocked;
        $blockedProfile = $blockedUser?->profile;

        return [
            'id'           => $this->id,
            'blocked_user' => $blockedProfile ? [
                'id'           => $blockedUser->id,
                'display_name' => $blockedProfile->display_name,
                'photo'        => $blockedProfile->photos->first()?->url,
            ] : null,
            'created_at'   => $this->created_at,
        ];
    }
}
