<?php

namespace App\Http\Resources;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MatchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $otherUser = $this->other_user;
        $otherProfile = $otherUser?->profile;

        return [
            'id'         => $this->id,
            'matched_at' => $this->created_at,
            'other_user' => $otherProfile ? [
                'id'           => $otherUser->id,
                'display_name' => $otherProfile->display_name,
                'age'          => $otherUser->date_of_birth
                    ? Carbon::parse($otherUser->date_of_birth)->age
                    : null,
                'is_verified'  => $otherProfile->verification_status === 'verified',
                'city'         => $otherProfile->city,
                'intention'    => $otherProfile->intention,
                'photo'        => $otherProfile->photos->first()?->url,
            ] : null,
        ];
    }
}
