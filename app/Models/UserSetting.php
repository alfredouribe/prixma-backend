<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class UserSetting extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'user_id',
        'selfie_verification_enabled',
        'incognito_mode_enabled',
        'geo_block_enabled',
        'reports_enabled',
        'notify_matches_enabled',
        'notify_messages_enabled',
        'notify_events_enabled',
    ];

    protected function casts(): array
    {
        return [
            'selfie_verification_enabled' => 'boolean',
            'incognito_mode_enabled'      => 'boolean',
            'geo_block_enabled'           => 'boolean',
            'reports_enabled'             => 'boolean',
            'notify_matches_enabled'      => 'boolean',
            'notify_messages_enabled'     => 'boolean',
            'notify_events_enabled'       => 'boolean',
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
