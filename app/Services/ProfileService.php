<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Jobs\ProcessProfileVideo;
use App\Models\Profile;
use App\Models\ProfilePhoto;
use App\Models\User;
use App\Models\UserSetting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

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

        $this->compressAndUploadToS3($file, $key);

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

    /**
     * Devuelve la configuración de privacidad/seguridad del usuario
     * (los mismos 4 toggles que se capturan en el paso "Safety" de
     * Onboarding). Cuentas creadas antes de que existiera esta tabla
     * (o cualquier caso borde donde el registro no se haya creado)
     * no deben recibir un 404 — se crea la fila con los defaults de la
     * migración (`selfie_verification_enabled: true`, `incognito_mode_enabled:
     * false`, `geo_block_enabled: false`, `reports_enabled: true`) vía
     * `firstOrCreate`, dejando que la base de datos aplique esos defaults
     * en vez de hardcodearlos aquí también.
     */
    public function getSettings(User $user): UserSetting
    {
        $settings = UserSetting::firstOrCreate(['user_id' => $user->id]);

        // `firstOrCreate` inserta solo `user_id` cuando la fila no existía,
        // dejando que MySQL aplique los defaults de columna en el INSERT.
        // La instancia en memoria, sin embargo, no refleja esos defaults
        // (los atributos nunca se asignaron en PHP) hasta recargarla.
        if ($settings->wasRecentlyCreated) {
            $settings = $settings->fresh();
        }

        return $settings;
    }

    public function updateSettings(User $user, array $data): UserSetting
    {
        $settings = UserSetting::firstOrCreate(['user_id' => $user->id]);
        $settings->fill($data);
        $settings->save();

        return $settings->fresh();
    }

    /**
     * Comprime una foto de perfil con ffmpeg (reduce calidad/tamaño) y sube
     * el resultado a S3 bajo la key dada. A diferencia del documento de
     * Verification, las fotos de perfil sí son de larga duración y públicas
     * — por eso el destino final es `Storage::disk('s3')` y no el disco
     * `local` (ver constitution.md → "Media Upload Pipeline"; la excepción
     * de guardar en disco local aplica solo a Verification). El archivo
     * temporal se borra siempre, incluso si la compresión falla.
     */
    private function compressAndUploadToS3(UploadedFile $file, string $key): void
    {
        $outputPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'profile_photo_' . Str::uuid() . '.jpg';

        try {
            $process = new Process([
                'ffmpeg',
                '-y',
                '-i', $file->getRealPath(),
                '-vf', "scale='min(1080,iw)':-2",
                '-q:v', '5',
                $outputPath,
            ]);
            $process->run();

            if (!$process->isSuccessful() || !file_exists($outputPath) || filesize($outputPath) === 0) {
                throw new BusinessException('No pudimos procesar tu foto. Verifica que la imagen no esté dañada e intenta de nuevo.');
            }

            Storage::disk('s3')->put($key, file_get_contents($outputPath));
        } finally {
            if (file_exists($outputPath)) {
                unlink($outputPath);
            }
        }
    }

    private function calculateStatistics(Profile $profile): array
    {
        $likesReceived = Schema::hasTable('swipes')
            ? DB::table('swipes')
                ->where('swiped_id', $profile->user_id)
                ->whereIn('direction', ['like', 'super_like'])
                ->count()
            : 0;

        $matchesCount = Schema::hasTable('user_matches')
            ? DB::table('user_matches')
                ->where(function ($q) use ($profile) {
                    $q->where('user_id_1', $profile->user_id)
                      ->orWhere('user_id_2', $profile->user_id);
                })
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
