<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ProfilePhoto extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'profile_id',
        'url',
        'key',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn($model) => $model->id = Str::uuid());
    }

    public function profile()
    {
        return $this->belongsTo(Profile::class);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('position');
    }
}
