<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class DeviceToken extends Model
{
    use HasFactory;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'user_id',
        'token',
        'platform',
    ];

    protected static function booted(): void
    {
        static::creating(fn($model) => $model->id = Str::uuid());
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
