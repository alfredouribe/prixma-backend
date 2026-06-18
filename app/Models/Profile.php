<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Profile extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'user_id',
        'display_name',
        'custom_gender_identity',
        'custom_orientation',
        'custom_pronouns',
        'custom_interests',
        'intention',
        'bio',
        'video_url',
        'video_processed',
        'photo_url',
        'onboarding_step',
        'onboarding_completed',
    ];

    protected function casts(): array
    {
        return [
            'video_processed'      => 'boolean',
            'onboarding_step'      => 'integer',
            'onboarding_completed' => 'boolean',
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

    public function genderIdentities()
    {
        return $this->belongsToMany(GenderIdentity::class, 'profile_gender_identities', 'profile_id', 'identity_id');
    }

    public function orientations()
    {
        return $this->belongsToMany(SexualOrientation::class, 'profile_sexual_orientations', 'profile_id', 'orientation_id');
    }

    public function pronouns()
    {
        return $this->belongsToMany(Pronoun::class, 'profile_pronouns', 'profile_id', 'pronoun_id');
    }

    public function interests()
    {
        return $this->belongsToMany(Interest::class, 'profile_interests', 'profile_id', 'interest_id');
    }
}
