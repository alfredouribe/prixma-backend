<?php

namespace App\Http\Resources;

use App\Models\UserMatch;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class PublicProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $hasVideo = (bool) ($this->video_url && $this->video_processed);

        return [
            'id'            => $this->id,
            'display_name'  => $this->display_name,
            'age'           => $this->whenLoaded('user', fn() =>
                $this->user?->date_of_birth ? Carbon::parse($this->user->date_of_birth)->age : null
            ),
            'bio'           => $this->bio,
            'city'          => $this->city,
            'intention'     => $this->intention,
            'is_verified'   => $this->verification_status === 'verified',
            'photo_url'     => $this->photo_url,
            'has_video'     => $hasVideo,
            'video_url'     => $this->when(
                $hasVideo && $this->hasActiveMatchWith($request),
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
            'custom_interests' => $this->custom_interests,
            'photos'        => $this->whenLoaded('photos', fn() =>
                ProfilePhotoResource::collection($this->photos)
            ),
        ];
    }

    /**
     * Verifica si existe un match activo entre el visitante autenticado y el
     * dueño de este perfil (`profiles.user_id`). Mismo criterio que usa
     * `MatchingService`: una fila en `matches` (modelo `UserMatch`) donde
     * `user_id_1`/`user_id_2` sean ambos usuarios, en cualquier orden.
     * Sin esto, `video_url` no debe exponerse nunca (ver constitution.md
     * y spec.md → "el video nunca se muestra públicamente hasta que hay match").
     */
    private function hasActiveMatchWith(Request $request): bool
    {
        $viewerId = $request->user()?->id;

        if (!$viewerId || $viewerId === $this->user_id) {
            return false;
        }

        $ownerId = $this->user_id;

        return UserMatch::where(function ($query) use ($viewerId, $ownerId) {
            $query->where('user_id_1', $viewerId)->where('user_id_2', $ownerId);
        })->orWhere(function ($query) use ($viewerId, $ownerId) {
            $query->where('user_id_1', $ownerId)->where('user_id_2', $viewerId);
        })->exists();
    }
}
