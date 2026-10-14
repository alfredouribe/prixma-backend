<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Reporte de bug / comentario libre enviado desde la app — ver
 * CLAUDE.md (pedido nuevo, sin spec previa). Log inmutable: se crea desde
 * `FeedbackService::submit()` y nunca se edita. El panel admin (Filament)
 * que lista/revisa estos registros lo construye otro agente a partir de
 * este modelo.
 */
class Feedback extends Model
{
    protected $table = 'feedback';

    public $timestamps = false;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'user_id',
        'type',
        'message',
        'app_version',
        'platform',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Feedback $model) {
            $model->id = Str::uuid();
            $model->created_at = $model->created_at ?? now();
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
