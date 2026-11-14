<?php

namespace App\Services\Media;

use App\Exceptions\MediaFailure;
use App\Models\MediaProject;
use Illuminate\Support\Facades\Storage;

class MediaInspector
{
    public function __construct(private MediaRuntime $runtime) {}

    public function inspect(MediaProject $project): array
    {
        $file = Storage::disk('local')->path($project->source_path);
        if (! is_file($file) || filesize($file) !== $project->expected_bytes) {
            throw new MediaFailure('upload_incomplete');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file);
        if (! in_array($mime, ['video/mp4', 'video/quicktime', 'video/x-matroska', 'video/webm', 'video/x-m4v', 'application/octet-stream'], true)) {
            throw new MediaFailure('unsupported_file');
        }
        $probe = $this->runtime->probe($file);
        $streams = collect($probe['streams'] ?? []);
        $video = $streams->firstWhere('codec_type', 'video');
        $audio = $streams->firstWhere('codec_type', 'audio');
        $duration = (float) ($probe['format']['duration'] ?? $video['duration'] ?? 0);
        $format = $probe['format']['format_name'] ?? '';
        if (! $video || ! preg_match('/(?:mov|mp4|matroska|webm)/', $format)) {
            throw new MediaFailure('unsupported_file');
        }
        if (! is_finite($duration) || $duration <= 0) {
            throw new MediaFailure('unreadable_duration');
        }
        if ($duration > ($project->storage_policy ?? app(StorageSettings::class)->policy())['max_duration']) {
            throw new MediaFailure('duration_exceeded');
        }
        if (! $audio || ! ($audio['sample_rate'] ?? 0)) {
            throw new MediaFailure('no_audio');
        }
        $width = (int) ($video['width'] ?? 0);
        $height = (int) ($video['height'] ?? 0);
        if ($width < 2 || $height < 2 || $width > 16384 || $height > 16384) {
            throw new MediaFailure('invalid_media');
        }
        $rotation = (int) ($video['tags']['rotate'] ?? collect($video['side_data_list'] ?? [])->firstWhere('side_data_type', 'Display Matrix')['rotation'] ?? 0);
        if (abs($rotation) % 180 === 90) {
            [$width, $height] = [$height, $width];
        }
        $this->runtime->execute('ffmpeg', [
            '-nostdin', '-v', 'error', '-protocol_whitelist', 'file,pipe', '-i', $file, '-t', '2', '-f', 'null', '-',
        ], timeout: 45);
        $poster = Storage::disk('local')->path('media/'.$project->id.'/poster.jpg');
        $this->runtime->execute('ffmpeg', [
            '-nostdin', '-v', 'error', '-y', '-ss', (string) min(1, $duration / 3), '-i', $file,
            '-frames:v', '1', '-vf', 'scale=640:-2', '-q:v', '3', $poster,
        ], timeout: 45);
        $a = $width;
        $b = $height;
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }

        return [
            'duration' => round($duration, 3), 'width' => $width, 'height' => $height,
            'aspect' => ($width / $a).':'.($height / $a), 'audio' => true,
            'mime' => $mime === 'application/octet-stream' ? 'video/'.$project->source_extension : $mime,
            'video_codec' => $video['codec_name'] ?? '', 'poster' => true,
        ];
    }
}
