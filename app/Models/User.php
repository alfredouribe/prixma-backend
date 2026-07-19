<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'email',
        'password',
        'status',
        'terms_accepted_at',
        'privacy_accepted_at',
        'email_verified_at',
        'date_of_birth',
        'onboarding_completed',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
            'privacy_accepted_at' => 'datetime',
            'date_of_birth' => 'date',
            'onboarding_completed' => 'boolean',
            'password' => 'hashed',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn($model) => $model->id = Str::uuid());
    }

    public function profile()
    {
        return $this->hasOne(Profile::class);
    }

    public function settings()
    {
        return $this->hasOne(UserSetting::class);
    }

    public function swipes()
    {
        return $this->hasMany(Swipe::class, 'swiper_id');
    }

    public function matchingPreferences()
    {
        return $this->hasOne(UserMatchingPreference::class);
    }

    public function matches()
    {
        return UserMatch::where('user_id_1', $this->id)->orWhere('user_id_2', $this->id);
    }

    public function blocksInitiated()
    {
        return $this->hasMany(Block::class, 'blocker_id');
    }

    public function blocksReceived()
    {
        return $this->hasMany(Block::class, 'blocked_id');
    }

    /**
     * IDs de usuarios que este usuario bloqueó.
     * Ver features/safety/specs/plan.md → "Integración con Matching".
     */
    public function blockedUserIds(): \Illuminate\Support\Collection
    {
        return Block::where('blocker_id', $this->id)->pluck('blocked_id');
    }

    /**
     * IDs de usuarios que bloquearon a este usuario.
     * Ver features/safety/specs/plan.md → "Integración con Matching".
     */
    public function blockedByUserIds(): \Illuminate\Support\Collection
    {
        return Block::where('blocked_id', $this->id)->pluck('blocker_id');
    }
}
