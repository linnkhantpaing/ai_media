<?php

namespace App\Services\Media;

use App\Exceptions\MediaFailure;
use App\Models\MediaRun;
use App\Support\MediaSettings;
use Illuminate\Support\Facades\Storage;

class MediaRenderer
{
    public function __construct(private MediaRuntime $runtime, private GeminiClient $gemini, private MediaStorage $storage) {}

    public function render(MediaRun $run, string $directory, callable $stage, callable $cancelled): array
    {
        $project = $run->project;
        $settings = MediaSettings::normalize($run->settings);
        $lines = $run->lines;
        if (! is_array($lines) || $lines === []) {
            throw new MediaFailure('invalid_script');
        }
        $source = $this->storage->materialize($project, $project->source_path);
        $base = 'media/'.$project->id.'/runs/'.$run->id.'/';
        $audioOnly = in_array($settings['output'], ['mp3', 'wav'], true);
        if ($settings['mode'] === 'dub' && $settings['keep_background'] && (! $audioOnly || $settings['audio_track'] === 'mix')) {
            $stage('separate');
            app(MediaBackground::class)->obtain($project, $cancelled);
            $stage('separate', true);
        }
        if ($settings['mode'] === 'dub' && $settings['output'] !== 'srt') {
            $stage('voice');
            [$source, $lines] = $this->dub($run, $source, $directory, $cancelled);
            $stage('voice', true);
        }
        $stage('subtitles');
        $this->writeSubtitles($lines, $settings, $directory, (int) $project->metadata['width'], (int) $project->metadata['height']);
        $stage('subtitles', true);
        $video = null;
        $audioOutput = null;
        $metadata = ['format' => 'srt', 'segments' => count($lines), 'bytes' => 0];
        if ($audioOnly) {
            $stage('render');
            $audioOutput = $base.'narration.'.$settings['output'];
            $args = ['-nostdin', '-v', 'error', '-y', '-i', $source, '-vn', '-ar', '48000', '-ac', '2'];
            array_push($args, ...($settings['output'] === 'mp3' ? ['-c:a', 'libmp3lame', '-b:a', '192k'] : ['-c:a', 'pcm_s16le']));
            array_push($args, '-map_metadata', '-1', $directory.'/narration.'.$settings['output']);
            $this->runtime->execute('ffmpeg', $args, directory: $directory, timeout: 180, cancelled: $cancelled);
            $stage('render', true);
        } elseif ($settings['output'] !== 'srt') {
            $stage('render');
            $target = $directory.'/recap.mp4';
            $this->runtime->execute('ffmpeg', [
                '-nostdin', '-v', 'error', '-y', '-i', $source,
                '-filter_complex', $this->filters($settings, $project->metadata),
                '-map', '[v]', '-map', '0:a:0', '-c:v', 'libx264', '-preset', 'fast', '-crf', '21',
                '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-b:a', '192k', '-movflags', '+faststart',
                '-map_metadata', '-1', '-threads', '2', $target,
            ], directory: $directory, timeout: 1400, cancelled: $cancelled);
            $video = $base.'recap.mp4';
            $stage('render', true);
        }
        $stage('verify');
        if (! is_file($directory.'/recap.srt') || filesize($directory.'/recap.srt') < 10) {
            throw new MediaFailure('render_verification_failed');
        }
        if ($video) {
            $probe = $this->runtime->probe($directory.'/recap.mp4');
            $stream = collect($probe['streams'] ?? [])->firstWhere('codec_type', 'video');
            $audio = collect($probe['streams'] ?? [])->firstWhere('codec_type', 'audio');
            $duration = (float) ($probe['format']['duration'] ?? 0);
            if (! $stream || ! $audio || $duration <= 0 || filesize($directory.'/recap.mp4') < 100) {
                throw new MediaFailure('render_verification_failed');
            }
            $metadata = ['format' => 'mp4', 'width' => $stream['width'], 'height' => $stream['height'], 'duration' => $duration, 'bytes' => filesize($directory.'/recap.mp4'), 'segments' => count($lines)];
        }
        if ($audioOutput) {
            $file = Storage::disk('local')->path($audioOutput);
            $probe = $this->runtime->probe($file);
            if (! collect($probe['streams'] ?? [])->firstWhere('codec_type', 'audio') || (float) ($probe['format']['duration'] ?? 0) <= 0) {
                throw new MediaFailure('render_verification_failed');
            }
            $metadata = ['format' => $settings['output'], 'duration' => (float) $probe['format']['duration'], 'bytes' => filesize($file), 'segments' => count($lines), 'audioTrack' => $settings['audio_track']];
        }
        $stage('verify', true);

        return ['video' => $video, 'audio' => $audioOutput, 'subtitles' => $base.'recap.srt', 'metadata' => $metadata];
    }

    private function dub(MediaRun $run, string $source, string $directory, callable $cancelled): array
    {
        $settings = MediaSettings::normalize($run->settings);
        $pieces = [];
        $translated = [];
        $cursor = 0.0;
        $timeline = 0.0;
        $background = null;
        $audioOnly = in_array($settings['output'], ['mp3', 'wav'], true);
        if ($settings['keep_background'] && (! $audioOnly || $settings['audio_track'] === 'mix')) {
            $background = app(MediaBackground::class)->obtain($run->project, $cancelled);
        }
        foreach ($run->lines as $line) {
            if ($cancelled()) {
                throw new MediaFailure('cancelled');
            }
            if ($line['start'] - $cursor > 0.04) {
                $gap = $line['start'] - $cursor;
                $pieces[] = ['start' => $cursor, 'length' => $gap, 'duration' => $gap, 'voice' => null];
                $timeline += $gap;
            }
            $prepared = app(MediaVoices::class)->line($run->project, $line, $settings, $cancelled);
            $voice = $prepared['path'];
            $voiceLength = $prepared['duration'];
            $original = $line['end'] - $line['start'];
            $duration = max($voiceLength, $original / $settings['max_video_speed']);
            $pieces[] = ['start' => $line['start'], 'length' => $original, 'duration' => $duration, 'voice' => $voice];
            $translated[] = [...$line, 'start' => $timeline, 'end' => $timeline + $duration];
            $timeline += $duration;
            $cursor = $line['end'];
        }
        $tail = $run->project->metadata['duration'] - $cursor;
        if ($tail > 0.04) {
            $pieces[] = ['start' => $cursor, 'length' => $tail, 'duration' => $tail, 'voice' => null];
        }
        $playlist = [];
        foreach ($pieces as $index => $piece) {
            $name = sprintf($audioOnly ? 'clip-%05d.wav' : 'clip-%05d.mp4', $index);
            $destination = $directory.'/'.$name;
            $args = ['-nostdin', '-v', 'error', '-y', '-ss', (string) $piece['start'], '-t', (string) $piece['length'], '-i', $source];
            if ($piece['voice']) {
                array_push($args, '-i', $piece['voice']);
            } else {
                array_push($args, '-f', 'lavfi', '-i', 'anullsrc=r=48000:cl=stereo');
            }
            if ($background) {
                array_push($args, '-ss', (string) $piece['start'], '-t', (string) $piece['length'], '-i', $background);
            }
            $filter = ($audioOnly ? '' : '[0:v]setpts=(PTS-STARTPTS)*'.($piece['duration'] / $piece['length']).',fps=30,scale=trunc(iw/2)*2:trunc(ih/2)*2,setsar=1,tpad=stop_mode=clone:stop_duration=0.1[v];').'[1:a]'.$this->tempo($piece['voice'] ? $settings['speech_rate'] : 1).',volume='.($piece['voice'] ? $settings['voice_volume'] : 0).',aresample=48000,aformat=channel_layouts=stereo,apad[a1]';
            if ($background) {
                $filter .= ';[2:a]'.$this->tempo($piece['length'] / $piece['duration']).',volume='.$settings['background_volume'].',aresample=48000,aformat=channel_layouts=stereo,apad[bg]';
                if ($settings['duck_background'] && $piece['voice']) {
                    $filter .= ';[a1]asplit=2[voice][side];[bg][side]sidechaincompress=threshold=0.03:ratio=4:attack=20:release=250[ducked];[voice][ducked]amix=inputs=2:normalize=0,alimiter=limit=0.95:level=false[a]';
                } else {
                    $filter .= ';[a1][bg]amix=inputs=2:normalize=0,alimiter=limit=0.95:level=false[a]';
                }
            } else {
                $filter .= ';[a1]anull[a]';
            }
            array_push($args, '-filter_complex', $filter);
            if (! $audioOnly) {
                array_push($args, '-map', '[v]', '-c:v', 'libx264', '-preset', 'fast', '-crf', '21', '-pix_fmt', 'yuv420p');
            }
            array_push($args, '-map', '[a]', '-t', (string) $piece['duration'], '-c:a', $audioOnly ? 'pcm_s16le' : 'aac', '-ar', '48000', '-threads', '2', $destination);
            $this->runtime->execute('ffmpeg', $args, timeout: 180, cancelled: $cancelled);
            $playlist[] = "file '".$name."'";
        }
        file_put_contents($directory.'/clips.txt', implode("\n", $playlist));
        $combined = $directory.($audioOnly ? '/dubbed.wav' : '/dubbed.mp4');
        $this->runtime->execute('ffmpeg', [
            '-nostdin', '-v', 'error', '-y', '-f', 'concat', '-safe', '1', '-i', 'clips.txt', '-c', 'copy', $combined,
        ], directory: $directory, timeout: 180, cancelled: $cancelled);

        return [$combined, $translated];
    }

    private function tempo(float $rate): string
    {
        $parts = [];
        while ($rate < 0.5) {
            $parts[] = 'atempo=0.5';
            $rate /= 0.5;
        }
        while ($rate > 2) {
            $parts[] = 'atempo=2';
            $rate /= 2;
        }
        $parts[] = 'atempo='.$rate;

        return implode(',', $parts);
    }

    private function writeSubtitles(array $lines, array $settings, string $directory, int $width, int $height): void
    {
        [$width, $height] = $this->dimensions($settings, ['width' => $width, 'height' => $height]);
        $color = ltrim($settings['subtitle_color'], '#');
        $assColor = '&H00'.substr($color, 4, 2).substr($color, 2, 2).substr($color, 0, 2);
        $fontSize = max(18, (int) round($height * 0.038));
        $margin = max(15, (int) round($height * $settings['subtitle_lift']));
        $ass = "[Script Info]\nScriptType: v4.00+\nPlayResX: {$width}\nPlayResY: {$height}\nWrapStyle: 0\n\n[V4+ Styles]\nFormat: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding\nStyle: Default,Padauk,{$fontSize},{$assColor},&H000000FF,&H00101010,&H80000000,0,0,0,0,100,100,0,0,1,2,1,2,25,25,{$margin},1\n\n[Events]\nFormat: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text\n";
        $srt = [];
        foreach ($lines as $index => $line) {
            $text = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $line['text']));
            $srtText = str_replace(['-->', '<', '>'], ['→', '‹', '›'], $text);
            $srt[] = ($index + 1)."\n".$this->timestamp($line['start']).' --> '.$this->timestamp($line['end'])."\n".$srtText."\n";
            $assText = str_replace(['\\', '{', '}'], ['＼', '｛', '｝'], $text);
            $ass .= 'Dialogue: 0,'.$this->timestamp($line['start'], true).','.$this->timestamp($line['end'], true).',Default,,0,0,0,,'.$assText."\n";
        }
        file_put_contents($directory.'/recap.srt', implode("\n", $srt));
        file_put_contents($directory.'/recap.ass', $ass);
        if ($settings['output'] !== 'burn' || ! $settings['burn_subtitles']) {
            return;
        }
        if (! is_dir($directory.'/fonts')) {
            mkdir($directory.'/fonts', 0755, true);
        }
        $font = config('media.font_directory').'/Padauk-Regular.ttf';
        if (! is_file($font)) {
            throw new MediaFailure('subtitle_font_missing');
        }
        copy($font, $directory.'/fonts/Padauk-Regular.ttf');
    }

    private function timestamp(float $seconds, bool $ass = false): string
    {
        $ticks = (int) round($seconds * ($ass ? 100 : 1000));
        $unit = $ass ? 100 : 1000;
        $whole = intdiv($ticks, $unit);
        $fraction = $ticks % $unit;

        return sprintf($ass ? '%d:%02d:%02d.%02d' : '%02d:%02d:%02d,%03d', intdiv($whole, 3600), intdiv($whole, 60) % 60, $whole % 60, $fraction);
    }

    private function dimensions(array $settings, array $metadata): array
    {
        $w = max(2, (int) floor($metadata['width'] / 2) * 2);
        $h = max(2, (int) floor($metadata['height'] / 2) * 2);
        if ($settings['aspect'] === 'original') {
            return [$w, $h];
        }
        $short = min(1080, $w, $h);

        return match ($settings['aspect']) {
            '9:16' => [$short, (int) round($short * 16 / 9 / 2) * 2],
            '16:9' => [(int) round($short * 16 / 9 / 2) * 2, $short],
            default => [$short, $short],
        };
    }

    private function filters(array $settings, array $metadata): string
    {
        [$w, $h] = $this->dimensions($settings, $metadata);
        $filter = '';
        if ($settings['aspect'] !== 'original' && in_array($settings['fill'], ['auto', 'blur'], true)) {
            $filter = '[0:v]split=2[bg][fg];[bg]scale='.$w.':'.$h.':force_original_aspect_ratio=increase,crop='.$w.':'.$h.',boxblur=20:2[back];[fg]scale='.$w.':'.$h.':force_original_aspect_ratio=decrease[front];[back][front]overlay=(W-w)/2:(H-h)/2,setsar=1[base]';
        } elseif ($settings['aspect'] !== 'original' && $settings['fill'] === 'crop') {
            $filter = '[0:v]scale='.$w.':'.$h.':force_original_aspect_ratio=increase,crop='.$w.':'.$h.',setsar=1[base]';
        } else {
            $filter = '[0:v]scale='.$w.':'.$h.':force_original_aspect_ratio=decrease,pad='.$w.':'.$h.':(ow-iw)/2:(oh-ih)/2:color=black,setsar=1[base]';
        }
        if ($settings['cover_mode'] === 'manual') {
            $top = min($h - 2, (int) floor($settings['cover_top'] * $h / 2) * 2);
            $height = min($h - $top, max(2, (int) floor($settings['cover_height'] * $h / 2) * 2));
            if ($settings['cover_style'] === 'dark_box') {
                $filter .= ';[base]drawbox=x=0:y='.$top.':w=iw:h='.$height.':color=black@0.9:t=fill[covered]';
            } else {
                $filter .= ';[base]split=2[main][band];[band]crop=iw:'.$height.':0:'.$top.',boxblur=8:2[blur];[main][blur]overlay=0:'.$top.'[covered]';
            }
        } else {
            $filter .= ';[base]null[covered]';
        }

        return $filter.($settings['burn_subtitles'] ? ';[covered]ass=recap.ass:fontsdir=fonts[v]' : ';[covered]null[v]');
    }
}
