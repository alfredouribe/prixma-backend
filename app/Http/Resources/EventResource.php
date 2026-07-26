<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class EventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'title'          => $this->title,
            'description'    => $this->description,
            'category'       => $this->category,
            'event_date'     => $this->event_date?->toIso8601String(),
            'location_name'  => $this->location_name,
            'latitude'       => $this->latitude !== null ? (float) $this->latitude : null,
            'longitude'      => $this->longitude !== null ? (float) $this->longitude : null,
            'external_link'  => $this->external_link,
            // Corrección 2026-07-26: `image_url` (columna) ya no se usa —
            // el bucket real es privado (ACLs deshabilitados), así que la
            // URL debe firmarse en cada respuesta a partir de `image_key`,
            // mismo patrón que `video_url` en ProfileResource/
            // PublicProfileResource (Storage::disk('s3')->temporaryUrl()).
            'image_url'      => $this->when(
                filled($this->image_key),
                fn () => Storage::disk('s3')->temporaryUrl($this->image_key, now()->addHours(4))
            ),
            // `not_going` nunca se expone como contador público — ver
            // domain.md → EventRsvp y spec.md → "Estados de asistencia".
            'interested_count' => (int) ($this->interested_count ?? 0),
            'going_count'       => (int) ($this->going_count ?? 0),
            'my_rsvp_status'    => $this->whenLoaded('rsvps', fn () => $this->rsvps->first()?->status),
            'created_at'        => $this->created_at?->toIso8601String(),
        ];
    }
}
