<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\Package;
use App\Models\PackageGrant;
use App\Models\User;
use Carbon\Carbon;

/**
 * Otorga manualmente un `Package` del catálogo a un usuario específico —
 * sin cobro real todavía, mismo criterio que `is_premium` (activado desde
 * Filament). Ver features/premium/specs/plan.md → "Catálogo de paquetes" y
 * domain.md → Package.
 */
class PackageService
{
    /**
     * `$grantedBy` es opcional (no obligatorio) para no romper los call
     * sites existentes de tests que no necesitan probar el admin — quedan
     * con `admin_id = null`, válido. El único caller de producción
     * (`UserResource::grantPackage`) sí lo pasa. Ver plan.md → "Historial de
     * paquetes otorgados".
     */
    public function grantToUser(User $user, Package $package, ?Admin $grantedBy = null): void
    {
        match ($package->grant_type) {
            'premium_days' => $user->update([
                'premium_until' => $this->extendFrom($user->premium_until, $package->grant_value, 'days'),
            ]),
            'boost_minutes' => $user->profile->update([
                'boosted_until' => $this->extendFrom($user->profile->boosted_until, $package->grant_value, 'minutes'),
            ]),
            'see_likers_days' => $user->update([
                'see_likers_until' => $this->extendFrom($user->see_likers_until, $package->grant_value, 'days'),
            ]),
            'rewind_credits' => $user->increment('rewind_credits', $package->grant_value),
            'super_like_credits' => $user->increment('extra_super_likes', $package->grant_value),
        };

        PackageGrant::create([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'admin_id' => $grantedBy?->id,
            'package_name' => $package->name,
            'grant_type' => $package->grant_type,
            'grant_value' => $package->grant_value,
        ]);
    }

    /**
     * Regla de "stacking" (confirmada como default razonable en plan.md, no
     * pedida explícitamente por el humano): si el beneficio actual sigue
     * vigente, el nuevo periodo se suma a partir de esa expiración; si ya
     * expiró (o nunca existió), se cuenta desde `now()`.
     */
    private function extendFrom(?Carbon $current, int $amount, string $unit): Carbon
    {
        $base = ($current && $current->isFuture()) ? $current : now();
        return $base->copy()->add($amount, $unit);
    }
}
