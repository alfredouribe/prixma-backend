<?php

namespace App\Jobs;

use App\Models\Profile;
use App\Services\VideoProcessingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProcessProfileVideo implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60;

    public function __construct(private readonly Profile $profile) {}

    public function handle(VideoProcessingService $processor): void
    {
        $rawKey = $this->profile->video_url;

        if (!$rawKey || !Storage::disk('s3')->exists($rawKey)) {
            Log::warning('ProcessProfileVideo: archivo raw no encontrado en S3.', [
                'profile_id' => $this->profile->id,
                'video_key'  => $rawKey,
            ]);
            return;
        }

        if (!$processor->isAvailable()) {
            Log::info('ProcessProfileVideo: ffmpeg no disponible, marcando como procesado (entorno local).', [
                'profile_id' => $this->profile->id,
            ]);
            $this->profile->update(['video_processed' => true]);
            return;
        }

        $tempDir       = sys_get_temp_dir() . '/' . Str::uuid();
        $rawPath       = null;
        $processedPath = null;
        $thumbPath     = null;

        try {
            mkdir($tempDir, 0755, true);

            $rawPath       = $tempDir . '/raw_' . basename($rawKey);
            $processedPath = $tempDir . '/processed.mp4';
            $thumbPath     = $tempDir . '/thumbnail.jpg';

            $this->downloadToPath($rawKey, $rawPath);

            $duration = $processor->getDuration($rawPath);
            $this->validateDuration($duration);

            $processor->transcode($rawPath, $processedPath);
            $processor->extractThumbnail($processedPath, $thumbPath);

            $userId         = $this->profile->user_id;
            $uuid           = Str::uuid();
            $processedKey   = "videos/profiles/{$userId}/{$uuid}.mp4";
            $thumbnailKey   = "videos/thumbnails/{$userId}/{$uuid}.jpg";

            Storage::disk('s3')->put($processedKey, file_get_contents($processedPath), 'private');
            Storage::disk('s3')->put($thumbnailKey, file_get_contents($thumbPath), 'private');

            Storage::disk('s3')->delete($rawKey);

            $this->profile->update([
                'video_url'           => $processedKey,
                'video_thumbnail_url' => $thumbnailKey,
                'video_processed'     => true,
            ]);

        } catch (\Throwable $e) {
            Log::error('ProcessProfileVideo: procesamiento fallido.', [
                'profile_id' => $this->profile->id,
                'reason'     => $e->getMessage(),
            ]);

            if ($rawKey) {
                Storage::disk('s3')->delete($rawKey);
            }

            $this->profile->update([
                'video_url'           => null,
                'video_thumbnail_url' => null,
                'video_processed'     => false,
            ]);

            throw $e;
        } finally {
            $this->cleanupDir($tempDir);
        }
    }

    private function downloadToPath(string $key, string $localPath): void
    {
        $contents = Storage::disk('s3')->get($key);
        file_put_contents($localPath, $contents);
    }

    private function validateDuration(float $duration): void
    {
        if ($duration < 30 || $duration > 60) {
            throw new \RuntimeException(
                "Duración inválida: {$duration}s. El video debe durar entre 30 y 60 segundos."
            );
        }
    }

    private function cleanupDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (glob($dir . '/*') as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        rmdir($dir);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessProfileVideo: fallo definitivo tras todos los reintentos.', [
            'profile_id' => $this->profile->id,
            'error'      => $exception->getMessage(),
        ]);
    }
}
