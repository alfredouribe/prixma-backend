<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Jobs\ProcessProfileVideo;
use App\Models\Profile;
use App\Models\User;
use App\Models\UserSetting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class OnboardingService
{
    public function getStatus(User $user): array
    {
        $profile = $user->profile()->with([
            'genderIdentities',
            'orientations',
            'pronouns',
            'interests',
        ])->first();

        return [
            'current_step' => $profile?->onboarding_step ?? 0,
            'completed'    => $profile?->onboarding_completed ?? false,
            'profile'      => $profile,
        ];
    }

    public function saveIdentity(User $user, array $data): Profile
    {
        $profile = Profile::firstOrNew(['user_id' => $user->id]);

        $profile->display_name           = $data['display_name'];
        $profile->custom_gender_identity = $data['custom_gender_identity'] ?? null;
        $profile->custom_orientation     = $data['custom_orientation'] ?? null;

        if ($profile->onboarding_step < 1) {
            $profile->onboarding_step = 1;
        }

        $profile->save();

        $profile->genderIdentities()->sync($data['gender_identity_ids'] ?? []);
        $profile->orientations()->sync($data['orientation_ids'] ?? []);

        return $profile->load(['genderIdentities', 'orientations']);
    }

    public function savePronouns(User $user, array $data): Profile
    {
        $profile = $this->requireProfile($user);

        $profile->custom_pronouns = $data['custom_pronouns'] ?? null;
        $profile->photo_url       = $data['photo_url'] ?? $profile->photo_url;

        if ($profile->onboarding_step < 2) {
            $profile->onboarding_step = 2;
        }

        $profile->save();

        $profile->pronouns()->sync($data['pronoun_ids'] ?? []);

        return $profile->load('pronouns');
    }

    public function saveIntention(User $user, array $data): Profile
    {
        $profile = $this->requireProfile($user);

        $profile->intention = $data['intention'];

        if ($profile->onboarding_step < 3) {
            $profile->onboarding_step = 3;
        }

        $profile->save();

        return $profile;
    }

    public function saveInterests(User $user, array $data): Profile
    {
        $profile = $this->requireProfile($user);

        $profile->custom_interests = $data['custom_interests'] ?? null;

        if ($profile->onboarding_step < 4) {
            $profile->onboarding_step = 4;
        }

        $profile->save();

        $profile->interests()->sync($data['interest_ids'] ?? []);

        return $profile->load('interests');
    }

    public function saveVideo(User $user, array $data): Profile
    {
        $profile = $this->requireProfile($user);

        $profile->video_url       = $data['video_key'];
        $profile->video_processed = false;

        if ($profile->onboarding_step < 5) {
            $profile->onboarding_step = 5;
        }

        $profile->save();

        ProcessProfileVideo::dispatch($profile);

        return $profile;
    }

    public function uploadRawVideo(User $user, UploadedFile $file): Profile
    {
        $profile = $this->requireProfile($user);

        if ($profile->video_url) {
            Storage::disk('s3')->delete($profile->video_url);
        }

        $ext = strtolower($file->getClientOriginalExtension()) ?: 'bin';
        $key = 'videos/raw/' . $user->id . '/' . Str::uuid() . '.' . $ext;

        Storage::disk('s3')->put($key, file_get_contents($file->getRealPath()), 'private');

        $profile->video_url           = $key;
        $profile->video_thumbnail_url = null;
        $profile->video_processed     = false;

        if ($profile->onboarding_step < 5) {
            $profile->onboarding_step = 5;
        }

        $profile->save();

        ProcessProfileVideo::dispatch($profile);

        return $profile;
    }

    public function saveSafety(User $user, array $data): void
    {
        DB::transaction(function () use ($user, $data) {
            UserSetting::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'selfie_verification_enabled' => $data['selfie_verification_enabled'],
                    'incognito_mode_enabled'      => $data['incognito_mode_enabled'],
                    'geo_block_enabled'           => $data['geo_block_enabled'],
                    'reports_enabled'             => $data['reports_enabled'],
                ]
            );

            $profile = $this->requireProfile($user);
            $profile->onboarding_step      = 6;
            $profile->onboarding_completed = true;
            $profile->save();

            $user->onboarding_completed = true;
            $user->save();
        });
    }

    private function requireProfile(User $user): Profile
    {
        $profile = $user->profile;

        if (!$profile) {
            throw new BusinessException('Debes completar el paso de identidad antes de continuar.');
        }

        return $profile;
    }

    public function generateVideoPresignedUrl(User $user): array
    {
        $key = 'videos/profiles/' . $user->id . '/' . Str::uuid() . '.mp4';

        $s3Client = Storage::disk('s3')->getClient();

        $command = $s3Client->getCommand('PutObject', [
            'Bucket'      => config('filesystems.disks.s3.bucket'),
            'Key'         => $key,
            'ContentType' => 'video/mp4',
            'ACL'         => 'private',
        ]);

        $presignedRequest = $s3Client->createPresignedRequest($command, '+15 minutes');

        return [
            'upload_url' => (string) $presignedRequest->getUri(),
            'video_key'  => $key,
        ];
    }
}
