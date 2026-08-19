<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'email',
        'password',
        'status',
        'terms_accepted_at',
        'privacy_accepted_at',
        'email_verified_at',
        'date_of_birth',
        'onboarding_completed',
        'is_premium',
        'premium_until',
        'see_likers_until',
        'rewind_credits',
        'extra_super_likes',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
            'privacy_accepted_at' => 'datetime',
            'date_of_birth' => 'date',
            'onboarding_completed' => 'boolean',
            'is_premium' => 'boolean',
            'premium_until' => 'datetime',
            'see_likers_until' => 'datetime',
            'rewind_credits' => 'integer',
            'extra_super_likes' => 'integer',
            'password' => 'hashed',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn($model) => $model->id = Str::uuid());
    }

    public function profile()
    {
        return $this->hasOne(Profile::class);
    }

    public function settings()
    {
        return $this->hasOne(UserSetting::class);
    }

    public function swipes()
    {
        return $this->hasMany(Swipe::class, 'swiper_id');
    }

    public function matchingPreferences()
    {
        return $this->hasOne(UserMatchingPreference::class);
    }

    public function matches()
    {
        return UserMatch::where('user_id_1', $this->id)->orWhere('user_id_2', $this->id);
    }

    public function blocksInitiated()
    {
        return $this->hasMany(Block::class, 'blocker_id');
    }

    public function blocksReceived()
    {
        return $this->hasMany(Block::class, 'blocked_id');
    }

    /**
     * Reportes que este usuario envió (como reportante). Usado por el panel
     * admin (`UserResource`/`ViewUser`, ver features/profile/specs/plan.md →
     * "Panel admin — gestión de usuarios") para mostrar el historial de
     * moderación de una cuenta. No existía ninguna relación en esta
     * dirección — solo `Report::reporter()` (belongsTo, inversa).
     */
    public function reportsSent()
    {
        return $this->hasMany(Report::class, 'reporter_id');
    }

    /**
     * Reportes que este usuario recibió (como reportado). Mismo uso que
     * `reportsSent()` — ver nota arriba.
     */
    public function reportsReceived()
    {
        return $this->hasMany(Report::class, 'reported_id');
    }

    /**
     * Historial inmutable de paquetes otorgados a este usuario (log, no
     * afecta el estado de perks — eso vive en las columnas
     * premium_until/see_likers_until/rewind_credits/extra_super_likes y en
     * profile.boosted_until). Usado por el panel admin (`ViewUser`), mismo
     * uso que `reportsSent()`/`reportsReceived()`. Ver
     * features/premium/specs/plan.md → "Historial de paquetes otorgados".
     */
    public function packageGrants()
    {
        return $this->hasMany(PackageGrant::class);
    }

    /**
     * IDs de usuarios que este usuario bloqueó.
     * Ver features/safety/specs/plan.md → "Integración con Matching".
     */
    public function blockedUserIds(): \Illuminate\Support\Collection
    {
        return Block::where('blocker_id', $this->id)->pluck('blocked_id');
    }

    /**
     * IDs de usuarios que bloquearon a este usuario.
     * Ver features/safety/specs/plan.md → "Integración con Matching".
     */
    public function blockedByUserIds(): \Illuminate\Support\Collection
    {
        return Block::where('blocked_id', $this->id)->pluck('blocker_id');
    }

    /**
     * Sobrescribe deliberadamente el método `notifications()` que trae el
     * trait `Notifiable` (que apunta a la tabla polimórfica nativa de
     * Laravel — `notifiable_type`/`notifiable_id` — un esquema distinto al
     * de nuestro `App\Models\Notification`, ver domain.md → Notification).
     * Un método definido directamente en la clase tiene prioridad sobre el
     * mismo método heredado de un trait, así que esto es seguro: `notify()`
     * (el que sí usamos para disparar el envío) no depende de esta
     * relación. Ver features/notifications/specs/plan.md.
     */
    public function notifications()
    {
        return $this->hasMany(Notification::class);
    }

    public function deviceTokens()
    {
        return $this->hasMany(DeviceToken::class);
    }

    /**
     * Suscripción recurrente real (RevenueCat/Google Play Billing), única
     * fuente de verdad escrita por el webhook — nunca por el cliente. Ver
     * domain.md → Subscription y features/subscriptions/specs/plan.md.
     */
    public function subscription()
    {
        return $this->hasOne(Subscription::class);
    }

    /**
     * Gate real de Premium usado en todo el sistema (límite de likes, ads,
     * video de chat) — no leer `is_premium` crudo directamente para gatear
     * comportamiento. Tres fuentes independientes, cualquiera basta:
     * `is_premium` (toggle manual sin expiración), `premium_until`
     * (otorgado por un `Package`, grant_type `premium_days`, expira solo,
     * ver features/premium/specs/plan.md → "Catálogo de paquetes" y
     * domain.md → Package) y `subscription.status === 'active'` (cobro
     * recurrente real vía RevenueCat, ver
     * features/subscriptions/specs/plan.md).
     */
    public function hasPremiumAccess(): bool
    {
        return $this->is_premium
            || ($this->premium_until !== null && $this->premium_until->isFuture())
            || $this->subscription?->status === 'active';
    }

    /**
     * Gate de "ver quién te dio like" (features/premium/specs/spec.md/plan.md
     * → "Ver quién te dio like"). Dos fuentes independientes, cualquiera
     * basta: Prixma+ completo (`hasPremiumAccess()`) o `see_likers_until`
     * vigente (otorgado por un `Package`, grant_type `see_likers_days`,
     * expira solo — ver "Catálogo de paquetes").
     */
    public function canSeeLikers(): bool
    {
        return $this->hasPremiumAccess()
            || ($this->see_likers_until !== null && $this->see_likers_until->isFuture());
    }
}
