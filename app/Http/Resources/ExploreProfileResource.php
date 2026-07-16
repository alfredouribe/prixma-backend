<?php

namespace App\Http\Resources;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExploreProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $profile = $this->profile;

        return [
            'id'               => $this->id,
            'display_name'     => $profile->display_name,
            'age'              => Carbon::parse($this->date_of_birth)->age,
            'pronouns'         => $profile->pronouns->pluck('label'),
            'gender_identities'=> $profile->genderIdentities->pluck('label'),
            'orientations'     => $profile->orientations->pluck('label'),
            'city'             => $profile->city,
            'bio'              => $profile->bio,
            'intention'        => $profile->intention,
            'is_verified'      => $profile->verification_status === 'verified',
            'has_video'        => $profile->video_url && $profile->video_processed,
            'interests'        => $profile->interests->pluck('label'),
            'photos'           => $profile->photos->map(fn($p) => [
                'id'       => $p->id,
                'url'      => $p->url,
                'position' => $p->position,
            ]),
        ];
    }
}
