<?php

namespace App\Jobs;

use App\Models\Profile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessProfileVideo implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60;

    public function __construct(private readonly Profile $profile) {}

    public function handle(): void
    {
        $videoKey = $this->profile->video_url;

        if (!$videoKey || !Storage::disk('s3')->exists($videoKey)) {
            Log::warning('ProcessProfileVideo: key no encontrada en S3.', [
                'profile_id' => $this->profile->id,
                'video_key'  => $videoKey,
            ]);
            return;
        }

        $tempPath = $this->downloadToTemp($videoKey);

        try {
            $this->validateFormat($videoKey);
            $duration = $this->getVideoDuration($tempPath);
            $this->validateDuration($duration);

            $this->profile->update(['video_processed' => true]);
        } catch (\RuntimeException $e) {
            Log::info('ProcessProfileVideo: video rechazado.', [
                'profile_id' => $this->profile->id,
                'reason'     => $e->getMessage(),
            ]);

            Storage::disk('s3')->delete($videoKey);

            $this->profile->update([
                'video_url'       => null,
                'video_processed' => false,
            ]);
        } finally {
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }
        }
    }

    private function downloadToTemp(string $key): string
    {
        $tempPath = sys_get_temp_dir() . '/' . basename($key);
        $contents = Storage::disk('s3')->get($key);
        file_put_contents($tempPath, $contents);
        return $tempPath;
    }

    private function validateFormat(string $key): void
    {
        $extension = strtolower(pathinfo($key, PATHINFO_EXTENSION));

        if (!in_array($extension, ['mp4', 'mov'])) {
            throw new \RuntimeException("Formato no permitido: {$extension}. Solo se aceptan MP4 y MOV.");
        }
    }

    private function getVideoDuration(string $path): float
    {
        if (!$this->ffprobeAvailable()) {
            // Sin ffprobe no podemos validar duración — aprobamos el video
            return 45.0;
        }

        $output = shell_exec(
            "ffprobe -v quiet -print_format json -show_format " . escapeshellarg($path) . " 2>/dev/null"
        );

        $data = json_decode($output ?? '', true);
        $duration = (float) ($data['format']['duration'] ?? 0);

        if ($duration === 0.0) {
            throw new \RuntimeException('No se pudo leer la duración del video.');
        }

        return $duration;
    }

    private function validateDuration(float $duration): void
    {
        if ($duration < 30 || $duration > 60) {
            throw new \RuntimeException(
                "Duración inválida: {$duration}s. El video debe durar entre 30 y 60 segundos."
            );
        }
    }

    private function ffprobeAvailable(): bool
    {
        return !empty(shell_exec('which ffprobe 2>/dev/null'));
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessProfileVideo: fallo definitivo.', [
            'profile_id' => $this->profile->id,
            'error'      => $exception->getMessage(),
        ]);
    }
}
