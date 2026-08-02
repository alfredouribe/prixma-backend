<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Fila única de configuración global — ver features/premium/specs/plan.md
 * → "platform_settings — fila única, no key-value genérico".
 */
class PlatformSetting extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'free_swipes_per_ad',
        'free_likes_per_day',
        'free_chat_minutes_before_ad',
        'chat_ad_video_key',
    ];

    protected function casts(): array
    {
        return [
            'free_swipes_per_ad' => 'integer',
            'free_likes_per_day' => 'integer',
            'free_chat_minutes_before_ad' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn ($model) => $model->id = Str::uuid());
    }

    /**
     * Trae la única fila de configuración, creándola con los defaults de la
     * migración si todavía no existe — evita depender de un seeder
     * obligatorio en cada entorno nuevo.
     */
    public static function current(): self
    {
        // `create([])` no basta: Eloquent no vuelve a leer de la BD las
        // columnas que no se asignaron explícitamente, así que el modelo en
        // memoria se quedaría con `null` en vez de los defaults reales de
        // la migración (aunque la fila en BD sí los tenga). Se repiten los
        // mismos defaults aquí a propósito.
        return static::query()->first() ?? static::create([
            'free_swipes_per_ad' => 5,
            'free_likes_per_day' => 5,
            'free_chat_minutes_before_ad' => 1,
        ]);
    }

    public function chatAdVideoUrl(): ?string
    {
        if (blank($this->chat_ad_video_key)) {
            return null;
        }

        return Storage::disk('s3')->temporaryUrl(
            $this->chat_ad_video_key,
            now()->addHours(4),
        );
    }
}
