<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Swipe extends Model
{
    public $timestamps = false;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'swiper_id',
        'swiped_id',
        'direction',
    ];

    protected static function booted(): void
    {
        static::creating(function ($model) {
            $model->id = Str::uuid();
            $model->created_at = now();
        });
    }

    public function swiper()
    {
        return $this->belongsTo(User::class, 'swiper_id');
    }

    public function swiped()
    {
        return $this->belongsTo(User::class, 'swiped_id');
    }
}
