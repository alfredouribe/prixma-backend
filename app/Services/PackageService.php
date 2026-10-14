<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\Package;
use App\Models\PackageGrant;
use App\Models\PlatformSetting;
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

    /**
     * Otorgamiento automático del sistema (no un `Package` del catálogo,
     * nunca visible/comprable) — pedido del humano: mientras el switch
     * `platform_settings.new_account_premium_trial_enabled` esté activado,
     * toda cuenta que se registre recibe Prixma+ por 30 días sin acción
     * manual. Apagar el switch después no afecta a quienes ya lo recibieron,
     * solo detiene nuevos otorgamientos a partir de ahí — por eso esto se
     * lee en cada registro, no se cachea.
     *
     * Reusa `extendFrom()` por consistencia con el resto del Service aunque
     * un usuario recién creado nunca tiene `premium_until` previo (decisión
     * de diseño, no pedida explícitamente) — un `User::create()` dentro de
     * `AuthService::register()` no puede tener otro grant previo en la misma
     * petición, así que el resultado es idéntico a partir siempre de
     * `now()`, pero deja la puerta abierta sin duplicar la regla si algún
     * día el registro deja de ser el único punto de entrada.
     *
     * Crea un `PackageGrant` con `package_id`/`admin_id` null (ambos ya
     * nullable en el esquema, ver domain.md → PackageGrant) para que
     * aparezca en el historial del panel igual que cualquier otro grant,
     * mostrando "Sistema" como otorgante — mismo fallback que ya usa esa UI
     * cuando `admin_id` es null.
     */
    public function grantWelcomeTrialIfEnabled(User $user): void
    {
        if (! PlatformSetting::current()->new_account_premium_trial_enabled) {
            return;
        }

        $days = 30;

        $user->update([
            'premium_until' => $this->extendFrom($user->premium_until, $days, 'days'),
        ]);

        PackageGrant::create([
            'user_id' => $user->id,
            'package_id' => null,
            'admin_id' => null,
            'package_name' => 'Premium de bienvenida (cuenta nueva)',
            'grant_type' => 'premium_days',
            'grant_value' => $days,
        ]);
    }
}
