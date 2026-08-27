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
            // Gate real, no la columna cruda — refleja tanto el toggle
            // manual (`is_premium`) como Premium temporal otorgado por un
            // Package (`premium_until`). Ver User::hasPremiumAccess() y
            // features/premium/specs/plan.md → "Catálogo de paquetes".
            'is_premium' => $this->resource->hasPremiumAccess(),
            // Fecha cruda de expiración del Premium otorgado por paquete
            // (grant_type premium_days) — null si nunca se otorgó uno, o si
            // ya expiró. El toggle manual (is_premium=true sin fecha) y una
            // suscripción activa no tienen fecha aquí; el frontend solo
            // muestra "activo hasta X" cuando este campo viene presente Y en
            // el futuro, y "activo" a secas en cualquier otro caso donde
            // is_premium ya sea true. Ver features/profile — sección
            // "Prixma+ y perks" en Mi Perfil.
            'premium_until' => $this->resource->premium_until,
            'free_swipes_per_ad' => $settings->free_swipes_per_ad,
            'free_likes_per_day' => $settings->free_likes_per_day,
            'free_chat_minutes_before_ad' => $settings->free_chat_minutes_before_ad,
            'chat_ad_video_url' => $settings->chatAdVideoUrl(),
            // Saldo de "deshacer swipe" — ver features/premium/specs/plan.md
            // → "Deshacer swipe / rewind": se expone aquí en vez de un
            // endpoint nuevo porque este endpoint ya se trae una vez por
            // sesión, mismo punto donde ExploreScreen ya vive.
            'rewind_credits' => $this->resource->rewind_credits,
            // Saldo de super likes extra — ver features/premium/specs/plan.md
            // → "Super likes extra". Mismo criterio que rewind_credits: se
            // consume solo cuando ya no queda límite diario gratis y el
            // swipe es específicamente super_like (ver
            // MatchingService::recordSwipe()). Se expone aquí para que el
            // perfil del usuario pueda mostrar cuántos le quedan.
            'extra_super_likes' => $this->resource->extra_super_likes,
        ];
    }
}
