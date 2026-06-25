<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class UserMatchingPreference extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'user_id',
        'age_min',
        'age_max',
        'max_distance_km',
        'intentions',
        'gender_identities',
        'orientations',
        'verified_only',
        'has_video_only',
    ];

    protected function casts(): array
    {
        return [
            'age_min'          => 'integer',
            'age_max'          => 'integer',
            'max_distance_km'  => 'integer',
            'intentions'       => 'array',
            'gender_identities'=> 'array',
            'orientations'     => 'array',
            'verified_only'    => 'boolean',
            'has_video_only'   => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn($model) => $model->id = Str::uuid());
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
