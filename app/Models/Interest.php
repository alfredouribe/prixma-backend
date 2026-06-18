<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Interest extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = ['slug', 'label', 'category'];

    protected static function booted(): void
    {
        static::creating(fn($model) => $model->id = Str::uuid());
    }
}
