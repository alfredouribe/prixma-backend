<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Profile extends Model
{
    use HasFactory;

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
        'city',
        'video_url',
        'video_thumbnail_url',
        'video_processed',
        'photo_url',
        'onboarding_step',
        'onboarding_completed',
        'latitude',
        'longitude',
        'verification_status',
        'boosted_until',
    ];

    protected function casts(): array
    {
        return [
            'video_processed'      => 'boolean',
            'onboarding_step'      => 'integer',
            'onboarding_completed' => 'boolean',
            'boosted_until'        => 'datetime',
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

    public function photos()
    {
        return $this->hasMany(ProfilePhoto::class)->orderBy('position');
    }

    public function verificationRequests()
    {
        return $this->hasMany(VerificationRequest::class)->orderByDesc('created_at');
    }

    /**
     * Snapshot congelado del perfil en un momento dado — usado por
     * SafetyService::createReport() para adjuntar evidencia al reporte tal
     * como se veía el perfil reportado al momento de reportar (ver
     * features/safety/specs/plan.md → "Reporte con bloqueo automático").
     */
    public function toSnapshotArray(): array
    {
        return [
            'display_name'          => $this->display_name,
            'bio'                   => $this->bio,
            'intention'              => $this->intention,
            'photos'                 => $this->photos->pluck('url')->values()->all(),
            'gender_identities'      => $this->genderIdentities->pluck('label')->values()->all(),
            'custom_gender_identity' => $this->custom_gender_identity,
            'orientations'           => $this->orientations->pluck('label')->values()->all(),
            'custom_orientation'     => $this->custom_orientation,
            'pronouns'               => $this->pronouns->pluck('label')->values()->all(),
            'custom_pronouns'        => $this->custom_pronouns,
            'interests'              => $this->interests->pluck('label')->values()->all(),
            'custom_interests'       => $this->custom_interests,
        ];
    }
}
