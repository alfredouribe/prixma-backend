<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Report extends Model
{
    use HasFactory;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'reporter_id',
        'reported_id',
        'reason',
        'description',
        'profile_snapshot',
        'chat_snapshot',
        'status',
    ];

    protected static function booted(): void
    {
        static::creating(fn($model) => $model->id = Str::uuid());
    }

    protected function casts(): array
    {
        return [
            'profile_snapshot' => 'array',
            'chat_snapshot'    => 'array',
        ];
    }

    public function reporter()
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function reported()
    {
        return $this->belongsTo(User::class, 'reported_id');
    }
}
