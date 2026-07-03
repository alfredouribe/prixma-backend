<?php

namespace App\Services;

class VideoProcessingService
{
    public function transcode(string $inputPath, string $outputPath): void
    {
        $cmd = sprintf(
            'ffmpeg -i %s -c:v libx264 -preset fast -crf 23 -c:a aac -b:a 128k -movflags +faststart %s -y 2>&1',
            escapeshellarg($inputPath),
            escapeshellarg($outputPath)
        );

        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0) {
            throw new \RuntimeException('FFmpeg no pudo transcodificar el video. Output: ' . implode("\n", $output));
        }
    }

    public function extractThumbnail(string $videoPath, string $thumbnailPath): void
    {
        $cmd = sprintf(
            'ffmpeg -i %s -ss 00:00:01 -vframes 1 -q:v 2 %s -y 2>&1',
            escapeshellarg($videoPath),
            escapeshellarg($thumbnailPath)
        );

        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0) {
            throw new \RuntimeException('FFmpeg no pudo extraer el thumbnail. Output: ' . implode("\n", $output));
        }
    }

    public function getDuration(string $videoPath): float
    {
        $cmd = sprintf(
            'ffprobe -v quiet -print_format json -show_format %s 2>/dev/null',
            escapeshellarg($videoPath)
        );

        $output = shell_exec($cmd);
        $data   = json_decode($output ?? '', true);
        $duration = (float) ($data['format']['duration'] ?? 0);

        if ($duration === 0.0) {
            throw new \RuntimeException('No se pudo leer la duración del video.');
        }

        return $duration;
    }

    public function isAvailable(): bool
    {
        return !empty(shell_exec('which ffmpeg 2>/dev/null'));
    }
}
