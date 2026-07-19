<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GeoBlockResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'label'      => $this->label,
            'latitude'   => $this->latitude,
            'longitude'  => $this->longitude,
            'radius_km'  => $this->radius_km,
            'created_at' => $this->created_at,
        ];
    }
}
