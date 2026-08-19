<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Suscripción recurrente real de Prixma+, escrita únicamente por el webhook
 * de RevenueCat (`SubscriptionService::handleRevenueCatEvent()`) — nunca se
 * confía un estado de suscripción que venga directo del cliente. Ver
 * domain.md → Subscription y features/subscriptions/specs/plan.md.
 *
 * Independiente de `users.is_premium` (toggle manual) y
 * `users.premium_until` (paquetes de un solo uso, ver
 * features/premium/specs/) — las tres fuentes conviven, `User::hasPremiumAccess()`
 * las combina.
 */
class Subscription extends Model
{
    use HasFactory;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'user_id',
        'plan',
        'status',
        'started_at',
        'expires_at',
        'provider',
        'provider_ref',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn ($model) => $model->id = Str::uuid());
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
