<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Conversation extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'user_id_1',
        'user_id_2',
        'type',
        'status',
        'match_id',
    ];

    protected static function booted(): void
    {
        static::creating(fn($model) => $model->id = Str::uuid());
    }

    public function user1()
    {
        return $this->belongsTo(User::class, 'user_id_1');
    }

    public function user2()
    {
        return $this->belongsTo(User::class, 'user_id_2');
    }

    public function match()
    {
        return $this->belongsTo(UserMatch::class, 'match_id');
    }
}
