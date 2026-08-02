<?php

namespace App\Http\Resources;

use App\Models\PlatformSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `resource` es el User autenticado — los 3 umbrales y el video vienen de
 * PlatformSetting::current(), no del propio usuario.
 */
class PremiumSettingsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $settings = PlatformSetting::current();

        return [
            'is_premium' => (bool) $this->is_premium,
            'free_swipes_per_ad' => $settings->free_swipes_per_ad,
            'free_likes_per_day' => $settings->free_likes_per_day,
            'free_chat_minutes_before_ad' => $settings->free_chat_minutes_before_ad,
            'chat_ad_video_url' => $settings->chatAdVideoUrl(),
        ];
    }
}
