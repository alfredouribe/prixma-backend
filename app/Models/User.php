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
}
