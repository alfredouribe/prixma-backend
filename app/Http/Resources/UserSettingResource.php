<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserSettingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                           => $this->id,
            'selfie_verification_enabled'  => $this->selfie_verification_enabled,
            'incognito_mode_enabled'       => $this->incognito_mode_enabled,
            'geo_block_enabled'            => $this->geo_block_enabled,
            'reports_enabled'              => $this->reports_enabled,
            'notify_matches_enabled'       => $this->notify_matches_enabled,
            'notify_messages_enabled'      => $this->notify_messages_enabled,
            'notify_events_enabled'        => $this->notify_events_enabled,
        ];
    }
}
