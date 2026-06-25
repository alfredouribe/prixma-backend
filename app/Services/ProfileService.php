<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Jobs\ProcessProfileVideo;
use App\Models\Profile;
use App\Models\ProfilePhoto;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProfileService
{
    public function getOwnProfile(User $user): array
    {
        $profile = $user->profile()->with([
            'genderIdentities',
            'orientations',
            'pronouns',
            'interests',
            'photos',
        ])->firstOrFail();

        return [
            'profile'    => $profile,
            'statistics' => $this->calculateStatistics($profile),
        ];
    }

    public function getPublicProfile(string $uuid): Profile
    {
        $profile = Profile::with([
            'genderIdentities',
            'orientations',
            'pronouns',
            'interests',
            'photos',
        ])->findOrFail($uuid);

        return $profile;
    }

    public function updateProfile(User $user, array $data): Profile
    {
        $profile = $user->profile ?? abort(404);

        $scalarFields = [
            'display_name', 'bio', 'city', 'intention',
            'custom_gender_identity', 'custom_orientation',
            'custom_pronouns', 'custom_interests',
        ];

        foreach ($scalarFields as $field) {
            if (array_key_exists($field, $data)) {
                $profile->$field = $data[$field];
            }
        }

        $profile->save();

        if (isset($data['gender_identity_ids'])) {
            $profile->genderIdentities()->sync($data['gender_identity_ids']);
        }
        if (isset($data['orientation_ids'])) {
            $profile->orientations()->sync($data['orientation_ids']);
        }
        if (isset($data['pronoun_ids'])) {
            $profile->pronouns()->sync($data['pronoun_ids']);
        }
        if (isset($data['interest_ids'])) {
            $profile->interests()->sync($data['interest_ids']);
        }

        return $profile->load(['genderIdentities', 'orientations', 'pronouns', 'interests', 'photos']);
    }

    public function addPhoto(User $user, UploadedFile $file): ProfilePhoto
    {
        $profile = $user->profile ?? abort(404);

        if ($profile->photos()->count() >= 6) {
            throw new BusinessException('Máximo 6 fotos permitidas.');
        }

        $key = 'photos/profiles/' . $user->id . '/' . Str::uuid() . '.jpg';

        Storage::disk('s3')->put($key, file_get_contents($file->getRealPath()));

        $url = Storage::disk('s3')->url($key);

        $nextPosition = $profile->photos()->max('position') + 1;

        $photo = $profile->photos()->create([
            'url'      => $url,
            'key'      => $key,
            'position' => $nextPosition,
        ]);

        if ($nextPosition === 1) {
            $profile->photo_url = $url;
            $profile->save();
        }

        return $photo;
    }

    public function deletePhoto(User $user, string $photoUuid): void
    {
        $profile = $user->profile ?? abort(404);

        $photo = $profile->photos()->findOrFail($photoUuid);
        $deletedPosition = $photo->position;

        Storage::disk('s3')->delete($photo->key);
        $photo->delete();

        $profile->photos()
            ->where('position', '>', $deletedPosition)
            ->orderBy('position')
            ->get()
            ->each(fn($p) => $p->decrement('position'));

        $firstPhoto = $profile->photos()->ordered()->first();
        $profile->photo_url = $firstPhoto?->url;
        $profile->save();
    }

    public function reorderPhotos(User $user, array $orderedIds): void
    {
        $profile = $user->profile ?? abort(404);

        $profilePhotoIds = $profile->photos()->pluck('id')->toArray();

        foreach ($orderedIds as $id) {
            if (!in_array($id, $profilePhotoIds)) {
                throw new BusinessException('Una o más fotos no pertenecen a tu perfil.');
            }
        }

        DB::transaction(function () use ($orderedIds, $profile) {
            foreach ($orderedIds as $position => $id) {
                $profile->photos()->where('id', $id)->update(['position' => $position + 1]);
            }

            $firstPhoto = $profile->photos()->ordered()->first();
            $profile->photo_url = $firstPhoto?->url;
            $profile->save();
        });
    }

    public function generatePhotoPresignedUrl(User $user): array
    {
        $key = 'photos/profiles/' . $user->id . '/' . Str::uuid() . '.jpg';

        $s3Client = Storage::disk('s3')->getClient();

        $command = $s3Client->getCommand('PutObject', [
            'Bucket'      => config('filesystems.disks.s3.bucket'),
            'Key'         => $key,
            'ContentType' => 'image/jpeg',
        ]);

        $presignedRequest = $s3Client->createPresignedRequest($command, '+15 minutes');

        return [
            'upload_url' => (string) $presignedRequest->getUri(),
            'photo_key'  => $key,
        ];
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

    public function saveVideo(User $user, \Illuminate\Http\UploadedFile $file): void
    {
        $profile = $user->profile ?? abort(404);

        if ($profile->video_url) {
            Storage::disk('s3')->delete($profile->video_url);
        }

        $ext = strtolower($file->getClientOriginalExtension()) ?: 'bin';
        $key = 'videos/raw/' . $user->id . '/' . Str::uuid() . '.' . $ext;

        Storage::disk('s3')->put($key, file_get_contents($file->getRealPath()), 'private');

        $profile->video_url           = $key;
        $profile->video_thumbnail_url = null;
        $profile->video_processed     = false;
        $profile->save();

        ProcessProfileVideo::dispatch($profile);
    }

    public function deleteVideo(User $user): void
    {
        $profile = $user->profile ?? abort(404);

        if ($profile->video_url) {
            Storage::disk('s3')->delete($profile->video_url);
        }

        $profile->video_url       = null;
        $profile->video_processed = false;
        $profile->save();
    }

    private function calculateStatistics(Profile $profile): array
    {
        $likesReceived = Schema::hasTable('swipes')
            ? DB::table('swipes')
                ->where('target_profile_id', $profile->id)
                ->where('direction', 'like')
                ->count()
            : 0;

        $matchesCount = Schema::hasTable('matches')
            ? DB::table('matches')
                ->where(function ($q) use ($profile) {
                    $q->where('profile_a_id', $profile->id)
                      ->orWhere('profile_b_id', $profile->id);
                })
                ->where('status', 'active')
                ->count()
            : 0;

        $eventsCount = Schema::hasTable('event_attendees')
            ? DB::table('event_attendees')
                ->where('user_id', $profile->user_id)
                ->count()
            : 0;

        return [
            'likes_received' => $likesReceived,
            'matches_count'  => $matchesCount,
            'events_count'   => $eventsCount,
        ];
    }
}
