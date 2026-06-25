<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class PublicProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'display_name'  => $this->display_name,
            'bio'           => $this->bio,
            'city'          => $this->city,
            'intention'     => $this->intention,
            'photo_url'     => $this->photo_url,
            'video_url'     => $this->when(
                $this->video_processed && $this->video_url,
                fn() => Storage::disk('s3')->temporaryUrl($this->video_url, now()->addHours(4))
            ),
            'gender_identities' => $this->whenLoaded('genderIdentities', fn() =>
                $this->genderIdentities->map(fn($g) => ['id' => $g->id, 'slug' => $g->slug, 'label' => $g->label])
            ),
            'orientations'  => $this->whenLoaded('orientations', fn() =>
                $this->orientations->map(fn($o) => ['id' => $o->id, 'slug' => $o->slug, 'label' => $o->label])
            ),
            'pronouns'      => $this->whenLoaded('pronouns', fn() =>
                $this->pronouns->map(fn($p) => ['id' => $p->id, 'slug' => $p->slug, 'label' => $p->label])
            ),
            'interests'     => $this->whenLoaded('interests', fn() =>
                $this->interests->map(fn($i) => ['id' => $i->id, 'slug' => $i->slug, 'label' => $i->label, 'category' => $i->category])
            ),
            'photos'        => $this->whenLoaded('photos', fn() =>
                ProfilePhotoResource::collection($this->photos)
            ),
        ];
    }
}
