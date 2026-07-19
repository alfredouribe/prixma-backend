<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class ProfileResource extends JsonResource
{
    private array $statistics = [];

    public function withStatistics(array $stats): static
    {
        $this->statistics = $stats;
        return $this;
    }

    public function toArray(Request $request): array
    {
        return [
            'id'                     => $this->id,
            'display_name'           => $this->display_name,
            'bio'                    => $this->bio,
            'city'                   => $this->city,
            'latitude'               => $this->latitude !== null ? (float) $this->latitude : null,
            'longitude'              => $this->longitude !== null ? (float) $this->longitude : null,
            'intention'              => $this->intention,
            'verification_status'    => $this->verification_status,
            'custom_gender_identity' => $this->custom_gender_identity,
            'custom_orientation'     => $this->custom_orientation,
            'custom_pronouns'        => $this->custom_pronouns,
            'custom_interests'       => $this->custom_interests,
            'photo_url'              => $this->photo_url,
            'video_url'              => $this->when(
                $this->video_processed && $this->video_url,
                fn() => Storage::disk('s3')->temporaryUrl($this->video_url, now()->addHours(4))
            ),
            'video_thumbnail_url'    => $this->when(
                $this->video_processed && $this->video_thumbnail_url,
                fn() => Storage::disk('s3')->temporaryUrl($this->video_thumbnail_url, now()->addHours(4))
            ),
            'video_processed'        => $this->video_processed,
            'onboarding_step'        => $this->onboarding_step,
            'onboarding_completed'   => $this->onboarding_completed,
            'gender_identities'      => $this->whenLoaded('genderIdentities', fn() =>
                $this->genderIdentities->map(fn($g) => ['id' => $g->id, 'slug' => $g->slug, 'label' => $g->label])
            ),
            'orientations'           => $this->whenLoaded('orientations', fn() =>
                $this->orientations->map(fn($o) => ['id' => $o->id, 'slug' => $o->slug, 'label' => $o->label])
            ),
            'pronouns'               => $this->whenLoaded('pronouns', fn() =>
                $this->pronouns->map(fn($p) => ['id' => $p->id, 'slug' => $p->slug, 'label' => $p->label])
            ),
            'interests'              => $this->whenLoaded('interests', fn() =>
                $this->interests->map(fn($i) => ['id' => $i->id, 'slug' => $i->slug, 'label' => $i->label, 'category' => $i->category])
            ),
            'photos'                 => $this->whenLoaded('photos', fn() =>
                ProfilePhotoResource::collection($this->photos)
            ),
            'statistics'             => $this->when(!empty($this->statistics), $this->statistics),
        ];
    }
}
