<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Notification extends Model
{
    use HasFactory;

    protected $table = 'notifications';

    protected $keyType = 'string';
    public $incrementing = false;

    // Solo `created_at` — domain.md no declara `updated_at` (una
    // notificación no se edita, solo se marca leída vía `read_at`), mismo
    // patrón que Message/UserMatch.
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'type',
        'title',
        'body',
        'data',
        'read_at',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'read_at' => 'datetime',
            'sent_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function ($model) {
            $model->id = Str::uuid();
            $model->created_at = $model->created_at ?? now();
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
