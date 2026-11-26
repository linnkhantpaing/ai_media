<?php

namespace App\Services\Media;

use App\Exceptions\MediaFailure;
use App\Models\MediaRun;
use App\Models\UsageRecord;
use App\Support\MediaSettings;
use Illuminate\Support\Facades\Storage;

class MediaRenderer
{
    public const AUDIO_REVISION = 'continuous-scene-audio-v1';

    public function __construct(private MediaRuntime $runtime, private GeminiClient $gemini, private MediaStorage $storage) {}

    public function render(MediaRun $run, string $directory, callable $stage, callable $cancelled): array
    {
        $project = $run->project;
        $settings = MediaSettings::normalize($run->settings);
        $lines = array_values(array_filter($run->lines ?? [], fn (array $line): bool => $line['included'] ?? true));
        if (! is_array($lines) || $lines === []) {
            throw new MediaFailure('invalid_script');
        }
        $source = $this->storage->materialize($project, $project->source_path);
        $base = 'media/'.$project->id.'/runs/'.$run->id.'/';
        $audioOnly = in_array($settings['output'], ['mp3', 'wav'], true);
        $background = null;
        $backgroundRanges = [];
        if ($settings['mode'] === 'dub' && $settings['output'] !== 'srt' && $settings['keep_background'] && (! $audioOnly || $settings['audio_track'] === 'mix')) {
            $stage('separate');
            $backgroundRanges = MediaBackground::plan($lines, (float) $project->metadata['duration'], $settings['workflow'] === 'movie');
            $background = app(MediaBackground::class)->obtain($project, $cancelled, $backgroundRanges, fn (int $percent) => $stage('separate', false, $percent));
            $stage('separate', true);
        }
        if ($settings['mode'] === 'dub' && $settings['output'] !== 'srt') {
            $stage('voice');
            [$source, $lines] = $this->dub($run, $source, $directory, $cancelled, fn (int $percent) => $stage('voice', false, $percent), $background, $backgroundRanges);
            $stage('voice', true);
        }
        $stage('subtitles');
        $this->writeSubtitles($lines, $settings, $directory, (int) $project->metadata['width'], (int) $project->metadata['height']);
        $stage('subtitles', true);
        $renderDuration = $settings['mode'] === 'dub' ? (float) (collect($lines)->max('end') ?? 0) : (float) $project->metadata['duration'];
        $video = null;
        $audioOutput = null;
        $metadata = ['format' => 'srt', 'segments' => count($lines), 'bytes' => 0];
        if ($audioOnly) {
            $stage('render');
            $audioOutput = $base.'narration.'.$settings['output'];
            $args = ['-nostdin', '-v', 'error', '-y', '-i', $source, '-vn', '-ar', '48000', '-ac', '2'];
            array_push($args, ...($settings['output'] === 'mp3' ? ['-c:a', 'libmp3lame', '-b:a', '192k'] : ['-c:a', 'pcm_s16le']));
            array_push($args, '-map_metadata', '-1', $directory.'/narration.'.$settings['output']);
            $this->runtime->execute('ffmpeg', $args, directory: $directory, timeout: 180, cancelled: $cancelled, progress: fn (int $percent) => $stage('render', false, $percent), duration: $renderDuration);
            $stage('render', true);
        } elseif ($settings['output'] !== 'srt') {
            $stage('render');
            $target = $directory.'/recap.mp4';
            $watermark = self::watermarked($run);
            $this->runtime->execute('ffmpeg', [
                '-nostdin', '-v', 'error', '-y', '-i', $source,
                ...($watermark ? ['-i', resource_path('brand/zatlan-logo.png')] : []),
                '-filter_complex', $this->filters($settings, $project->metadata, $watermark),
                ...($settings['mode'] === 'subs' ? $this->sourceAudioFilters($lines) : []),
                '-map', '[v]', '-map', '0:a:0', '-c:v', 'libx264', '-preset', 'fast', '-crf', '21',
                '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-b:a', '192k', '-movflags', '+faststart',
                '-map_metadata', '-1', '-threads', '2', $target,
            ], directory: $directory, timeout: 1400, cancelled: $cancelled, progress: fn (int $percent) => $stage('render', false, $percent), duration: $renderDuration);
            $video = $base.'recap.mp4';
            $stage('render', true);
        }
        $stage('verify');
        if (! is_file($directory.'/recap.srt') || (collect($lines)->contains(fn (array $line): bool => ! ($line['subtitle_hidden'] ?? false)) && filesize($directory.'/recap.srt') < 10)) {
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
        $metadata['styled'] = true;
        $stage('verify', true);

        return ['video' => $video, 'audio' => $audioOutput, 'subtitles' => $base.'recap.srt', 'metadata' => $metadata];
    }

    private function dub(MediaRun $run, string $source, string $directory, callable $cancelled, callable $progress, ?string $background, array $backgroundRanges): array
    {
        $settings = MediaSettings::normalize($run->settings);
        $pieces = [];
        $translated = [];
        $cursor = 0.0;
        $timeline = 0.0;
        $allAudioMuted = collect($run->lines ?? [])->filter(fn (array $line): bool => $line['included'] ?? true)->every(fn (array $line): bool => $line['audio_muted'] ?? false);
        $audioOnly = in_array($settings['output'], ['mp3', 'wav'], true);
        $included = array_values(array_filter($run->lines ?? [], fn (array $line): bool => $line['included'] ?? true));
        foreach ($included as $lineIndex => $line) {
            if ($cancelled()) {
                throw new MediaFailure('cancelled');
            }
            if ($settings['workflow'] !== 'movie' && $line['start'] - $cursor > 0.04) {
                $gap = $line['start'] - $cursor;
                $pieces[] = ['start' => $cursor, 'length' => $gap, 'duration' => $gap, 'voice' => null, 'muted' => $allAudioMuted];
                $timeline += $gap;
            }
            if (MediaNarrationFit::enabled($settings) && ($line['audio_muted'] ?? false)) {
                $duration = self::sceneDuration($line, $settings, 0);
                $pieces[] = ['start' => $line['start'], 'length' => $line['end'] - $line['start'], 'duration' => $duration, 'voice' => null, 'muted' => true];
                $translated[] = [...$line, 'start' => $timeline, 'end' => $timeline + $duration];
                $timeline += $duration;
                $cursor = $line['end'];
                $progress((int) floor(($lineIndex + 1) / count($included) * 50));

                continue;
            }
            $prepared = app(MediaVoices::class)->line($run->project, $line, $settings, $cancelled);
            $prepared = app(MediaVoices::class)->prepareScene($prepared, $settings, $directory, $cancelled);
            $voice = $prepared['path'];
            $voiceLength = $prepared['duration'];
            $original = $line['end'] - $line['start'];
            $duration = self::sceneDuration($line, $settings, $voiceLength);
            $pieces[] = ['start' => $line['start'], 'length' => $original, 'duration' => $duration, 'voice' => $voice, 'voice_duration' => $prepared['raw_duration'], 'muted' => (bool) ($line['audio_muted'] ?? false)];
            $translated[] = [...$line, 'start' => $timeline, 'end' => $timeline + $duration];
            $timeline += $duration;
            $cursor = $line['end'];
            $progress((int) floor(($lineIndex + 1) / count($included) * 50));
        }
        // Audio often runs a little past the last video frame (e.g. video 57.90 s, audio 57.98 s).
        // A piece that starts after the last frame has no picture to encode, so the tail stops at the
        // last frame and any scene starting after it shows a held last frame instead.
        $videoEnd = $audioOnly ? null : $this->videoEnd($source, (float) $run->project->metadata['duration']);
        $tail = min((float) $run->project->metadata['duration'], $videoEnd ?? PHP_FLOAT_MAX) - $cursor;
        if ($settings['workflow'] !== 'movie' && $tail > 0.04) {
            $pieces[] = ['start' => $cursor, 'length' => $tail, 'duration' => $tail, 'voice' => null, 'muted' => $allAudioMuted];
        }
        $playlist = [];
        foreach ($pieces as $index => $piece) {
            $name = sprintf($audioOnly ? 'clip-%05d.wav' : 'clip-%05d.mp4', $index);
            $destination = $directory.'/'.$name;
            $signature = hash('sha256', json_encode([self::AUDIO_REVISION, $piece, $settings, $source, $background], JSON_THROW_ON_ERROR));
            $checkpoint = $destination.'.sha256';
            if (is_file($destination) && is_file($checkpoint) && hash_equals($signature, trim(file_get_contents($checkpoint)))) {
                $playlist[] = "file '".$name."'";
                $progress(50 + (int) floor(($index + 1) / count($pieces) * 45));

                continue;
            }
            $held = $videoEnd !== null && $piece['start'] > $videoEnd - 0.05;
            $videoStart = $held ? max(0.0, $videoEnd - 0.1) : $piece['start'];
            $args = ['-nostdin', '-v', 'error', '-y', '-ss', (string) $videoStart, '-t', (string) ($held ? 0.1 : $piece['length']), '-i', $source];
            if ($piece['voice']) {
                array_push($args, '-i', $piece['voice']);
            } else {
                array_push($args, '-f', 'lavfi', '-i', 'anullsrc=r=48000:cl=stereo');
            }
            if ($background) {
                array_push($args, '-ss', (string) MediaBackground::offset($backgroundRanges, (float) $piece['start']), '-t', (string) $piece['length'], '-i', $background);
            }
            $filter = ($audioOnly ? '' : ($held
                ? '[0:v]setpts=PTS-STARTPTS,fps=30,scale=trunc(iw/2)*2:trunc(ih/2)*2,setsar=1,tpad=stop_mode=clone:stop_duration='.($piece['duration'] + 0.1).'[v];'
                : '[0:v]setpts=(PTS-STARTPTS)*'.($piece['duration'] / $piece['length']).',fps=30,scale=trunc(iw/2)*2:trunc(ih/2)*2,setsar=1,tpad=stop_mode=clone:stop_duration=0.1[v];')).'[1:a]'.($piece['voice'] ? app(MediaVoices::class)->sceneFilter($piece['voice_duration'], $piece['duration'], $settings) : $this->tempo(1)).',volume='.($piece['voice'] && ! ($piece['muted'] ?? false) ? $settings['voice_volume'] : 0).',aresample=48000,aformat=channel_layouts=stereo,apad,atrim=duration='.$piece['duration'].'[a1]';
            if ($background) {
                $filter .= ';[2:a]'.$this->tempo($piece['length'] / $piece['duration']).',volume='.(($piece['muted'] ?? false) ? 0 : $settings['background_volume']).',aresample=48000,aformat=channel_layouts=stereo,apad[bg]';
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
            $this->runtime->execute('ffmpeg', $args, timeout: 180, cancelled: $cancelled, progress: fn (int $percent) => $progress(50 + (int) floor(($index + $percent / 100) / count($pieces) * 45)), duration: (float) $piece['duration']);
            file_put_contents($checkpoint, $signature, LOCK_EX);
            $playlist[] = "file '".$name."'";
        }
        file_put_contents($directory.'/clips.txt', implode("\n", $playlist));
        $combined = $directory.($audioOnly ? '/dubbed.wav' : '/dubbed.mp4');
        $this->runtime->execute('ffmpeg', [
            '-nostdin', '-v', 'error', '-y', '-f', 'concat', '-safe', '1', '-i', 'clips.txt', '-c', 'copy', $combined,
        ], directory: $directory, timeout: 180, cancelled: $cancelled, progress: fn (int $percent) => $progress(95 + (int) floor($percent * 0.05)), duration: $timeline + max(0, $settings['workflow'] !== 'movie' ? $tail : 0));

        return [$combined, $translated];
    }

    /** Time of the last video frame (falls back to the container duration when the stream has none). */
    private function videoEnd(string $source, float $fallback): float
    {
        try {
            $video = collect($this->runtime->probe($source)['streams'] ?? [])->firstWhere('codec_type', 'video');
            $end = (float) ($video['duration'] ?? 0);

            return $end > 0 ? $end : $fallback;
        } catch (MediaFailure) {
            return $fallback;
        }
    }

    public static function sceneDuration(array $line, array $settings, float $voiceDuration): float
    {
        $sourceDuration = $line['end'] - $line['start'];

        if (MediaNarrationFit::enabled($settings)) {
            return (float) ($line['planned_duration'] ?? $sourceDuration);
        }

        return $settings['workflow'] === 'movie'
            ? max($voiceDuration, $sourceDuration / $settings['max_video_speed'], (float) ($line['planned_duration'] ?? $sourceDuration))
            : max($voiceDuration, $sourceDuration);
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

    /** @return list<string> */
    private function sourceAudioFilters(array $lines): array
    {
        if ($lines !== [] && collect($lines)->every(fn (array $line): bool => $line['audio_muted'] ?? false)) {
            return ['-af', 'volume=0'];
        }
        $filters = [];
        foreach ($lines as $line) {
            if ($line['audio_muted'] ?? false) {
                $start = number_format((float) $line['start'], 6, '.', '');
                $end = number_format((float) $line['end'], 6, '.', '');
                $filters[] = "volume=0:enable='gte(t,{$start})*lt(t,{$end})'";
            }
        }

        return $filters === [] ? [] : ['-af', implode(',', $filters)];
    }

    private function writeSubtitles(array $lines, array $settings, string $directory, int $width, int $height): void
    {
        [$width, $height] = $this->dimensions($settings, ['width' => $width, 'height' => $height]);
        $color = ltrim($settings['subtitle_color'], '#');
        $assColor = '&H00'.substr($color, 4, 2).substr($color, 2, 2).substr($color, 0, 2);
        $fontSize = $settings['subtitle_size'] > 0 ? $settings['subtitle_size'] : max(18, (int) round($height * 0.038));
        $fontName = $settings['subtitle_font'];
        $bold = $settings['subtitle_bold'] ? -1 : 0;
        $outline = $settings['subtitle_outline'];
        $shadow = $settings['subtitle_shadow'];
        $alignment = match ($settings['subtitle_position']) {
            'top' => 8, 'middle' => 5, default => 2
        };
        $box = $settings['subtitle_background'] ? 3 : 1;
        $opacity = sprintf('%02X', (int) round((1 - $settings['subtitle_opacity']) * 255));
        $margin = max(15, (int) round($height * $settings['subtitle_lift']));
        $ass = "[Script Info]\nScriptType: v4.00+\nPlayResX: {$width}\nPlayResY: {$height}\nWrapStyle: 0\n\n[V4+ Styles]\nFormat: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding\nStyle: Default,{$fontName},{$fontSize},{$assColor},&H000000FF,&H00101010,&H{$opacity}000000,{$bold},0,0,0,100,100,0,0,{$box},{$outline},{$shadow},{$alignment},25,25,{$margin},1\n\n[Events]\nFormat: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text\n";
        $srt = [];
        $captions = [];
        foreach ($lines as $line) {
            if ($line['subtitle_hidden'] ?? false) {
                continue;
            }
            $text = trim((string) ($line['subtitle_text'] ?? $line['text']));
            $parts = [];
            while (mb_strlen($text) > 100) {
                $parts[] = mb_substr($text, 0, 100);
                $text = mb_substr($text, 100);
            }
            if ($text !== '') {
                $parts[] = $text;
            }
            $size = max(1, array_sum(array_map('mb_strlen', $parts)));
            $cursor = (float) $line['start'];
            foreach ($parts as $part) {
                $end = $cursor + ($line['end'] - $line['start']) * mb_strlen($part) / $size;
                $captions[] = [...$line, 'start' => $cursor, 'end' => $end, 'subtitle_text' => $part];
                $cursor = $end;
            }
        }
        foreach ($captions as $index => $line) {
            $text = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', ($line['subtitle_text'] ?? $line['text'])));
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
        if ($settings['subtitle_font'] !== 'Padauk') {
            $font = PHP_OS_FAMILY === 'Windows' ? 'C:/Windows/Fonts/'.($settings['subtitle_font'] === 'Arial' ? 'arial.ttf' : 'tahoma.ttf') : config('media.font_directory').'/'.$settings['subtitle_font'].'.ttf';
        }
        if (! is_file($font)) {
            throw new MediaFailure('subtitle_font_missing');
        }
        copy($font, $directory.'/fonts/'.basename($font));
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
        $ceiling = match ($settings['quality'] ?? 'source') {
            '720p' => 720, '1080p' => 1080, default => min($w, $h)
        };
        $factor = min(1, $ceiling / min($w, $h));
        $w = max(2, (int) floor($w * $factor / 2) * 2);
        $h = max(2, (int) floor($h * $factor / 2) * 2);
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

    private function blurFilter(int $radius): string
    {
        return "boxblur=luma_radius='min({$radius},max(0,floor(min(w,h)/2)-1))':luma_power=2:chroma_radius='min({$radius},max(0,floor(min(cw,ch)/2)-1))':chroma_power=2";
    }

    /**
     * Whether this export carries the ZATLAN watermark: decided by the plan the run was
     * reserved under (its usage snapshot), so a later plan change cannot alter a running export.
     */
    public static function watermarked(MediaRun $run): bool
    {
        $snapshot = UsageRecord::where('media_run_id', $run->id)->value('package_snapshot');
        $snapshot = is_string($snapshot) ? json_decode($snapshot, true) : $snapshot;

        return (bool) ($snapshot['definition']['watermark'] ?? false);
    }

    private function filters(array $settings, array $metadata, bool $watermark = false): string
    {
        [$w, $h] = $this->dimensions($settings, $metadata);
        $filter = '';
        if ($settings['aspect'] !== 'original' && in_array($settings['fill'], ['auto', 'blur'], true)) {
            $filter = '[0:v]split=2[bg][fg];[bg]scale='.$w.':'.$h.':force_original_aspect_ratio=increase,crop='.$w.':'.$h.','.$this->blurFilter(20).'[back];[fg]scale='.$w.':'.$h.':force_original_aspect_ratio=decrease[front];[back][front]overlay=(W-w)/2:(H-h)/2,setsar=1[base]';
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
                $filter .= ';[base]split=2[main][band];[band]crop=iw:'.$height.':0:'.$top.','.$this->blurFilter(8).'[blur];[main][blur]overlay=0:'.$top.'[covered]';
            }
        } else {
            $filter .= ';[base]null[covered]';
        }

        $final = $watermark ? '[marked]' : '[v]';
        $filter .= $settings['burn_subtitles'] ? ';[covered]ass=recap.ass:fontsdir=fonts'.$final : ';[covered]null'.$final;
        if ($watermark) {
            // Logo in the top-right corner (subtitles sit at the bottom), ~18% of the shorter side, 60% opacity.
            $width = max(64, (int) (round(min($w, $h) * 0.18 / 2) * 2));
            $margin = max(12, (int) round(min($w, $h) * 0.03));
            $filter .= ';[1:v]scale='.$width.':-2,format=rgba,colorchannelmixer=aa=0.6[logo];[marked][logo]overlay=W-w-'.$margin.':'.$margin.':format=auto[v]';
        }

        return $filter;
    }
}
