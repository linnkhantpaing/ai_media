<?php

namespace App\Services\Media;

use App\Exceptions\MediaFailure;
use App\Models\MediaProject;
use App\Models\MediaRun;
use App\Services\UsageLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class MediaPipeline
{
    public function __construct(private MediaRuntime $runtime, private GeminiClient $gemini, private MediaRenderer $renderer) {}

    public function check(MediaRun $run): void
    {
        $run->refresh();
        $project = $run->project()->first();
        if (! $project || $project->active_run_id !== $run->id || $run->cancel_requested || $run->status === 'cancelled') {
            throw new MediaFailure('cancelled');
        }
    }

    public function directory(MediaRun $run): string
    {
        $relative = 'media/'.$run->media_project_id.'/runs/'.$run->id;
        Storage::disk('local')->makeDirectory($relative);

        return Storage::disk('local')->path($relative);
    }

    public function stage(MediaRun $run, string $key, bool $complete = false): void
    {
        $this->check($run);
        $stages = $run->stages ?? [];
        $stages[$key] = $complete ? 'completed' : 'active';
        $progress = (int) floor(count(array_filter($stages, fn (string $state) => $state === 'completed')) / max(1, count($stages)) * 100);
        $status = $run->phase === 'analysis' ? ($key === 'translate' ? 'translating' : 'analyzing') : 'rendering';
        $run->forceFill(['stage' => $key, 'stages' => $stages, 'progress' => $progress, 'status' => $status, 'error_code' => null])->save();
        MediaProject::whereKey($run->media_project_id)->where('active_run_id', $run->id)
            ->where('status', '!=', 'cancel_requested')->update(['status' => $status, 'error_code' => null]);
    }

    public function analyze(MediaRun $run): void
    {
        $this->check($run);
        $directory = $this->directory($run);
        $project = $run->project;
        if (($run->stages ?? []) === []) {
            $run->forceFill(['stages' => array_fill_keys(['prepare', 'transcribe', 'context', 'translate'], 'waiting'), 'started_at' => now()])->save();
        }
        $this->stage($run, 'prepare');
        $audio = $directory.'/source.mp3';
        if (! is_file($audio) || filesize($audio) === 0) {
            $this->runtime->execute('ffmpeg', [
                '-nostdin', '-v', 'error', '-y', '-i', app(MediaStorage::class)->materialize($project, $project->source_path),
                '-vn', '-ac', '1', '-ar', '16000', '-b:a', '48k', '-f', 'mp3', $audio,
            ], timeout: 180, cancelled: fn () => $this->cancelled($run));
        }
        $this->stage($run, 'prepare', true);
        $this->stage($run, 'transcribe');
        $lines = $run->lines ?? [];
        if ($lines === []) {
            $result = $this->gemini->transcribe($audio, $run->settings['source_lang']);
            $this->check($run);
            $lines = $this->validateTranscript($result['segments'] ?? null, (float) $project->metadata['duration']);
            $artifacts = $run->artifacts ?? [];
            $artifacts['context'] = mb_substr((string) ($result['summary'] ?? ''), 0, 8000);
            $run->forceFill(['lines' => $lines, 'artifacts' => $artifacts])->save();
        }
        $this->stage($run, 'transcribe', true);
        $this->stage($run, 'context');
        $this->stage($run, 'context', true);
        $this->stage($run, 'translate');
        foreach (array_chunk($lines, 30, true) as $chunk) {
            $pending = array_filter($chunk, fn (array $line) => trim((string) ($line['text'] ?? '')) === '');
            if ($pending === []) {
                continue;
            }
            $response = $this->gemini->translate(array_values($pending), (string) ($run->artifacts['context'] ?? ''));
            $this->check($run);
            $translations = $response['translations'] ?? null;
            if (! is_array($translations) || count($translations) !== count($pending)) {
                throw new MediaFailure('provider_response_invalid');
            }
            $seen = [];
            foreach ($translations as $translation) {
                $index = $translation['index'] ?? null;
                $text = $translation['text'] ?? null;
                if (! is_int($index) || ! isset($pending[$index]) || isset($seen[$index]) || ! is_string($text) || trim($text) === '' || mb_strlen($text) > 1500) {
                    throw new MediaFailure('provider_response_invalid');
                }
                $lines[$index]['text'] = trim($text);
                $seen[$index] = true;
            }
            $run->forceFill(['lines' => $lines])->save();
        }
        $this->stage($run, 'translate', true);
        DB::transaction(function () use ($run, $lines): void {
            $current = MediaRun::lockForUpdate()->findOrFail($run->id);
            $project = MediaProject::lockForUpdate()->findOrFail($current->media_project_id);
            if ($current->cancel_requested || $project->active_run_id !== $current->id) {
                throw new MediaFailure('cancelled');
            }
            $review = $current->settings['review_before_render'];
            $project->forceFill(['lines' => array_values($lines), 'status' => $review ? 'awaiting_review' : 'rendering'])->save();
            $current->forceFill([
                'lines' => array_values($lines), 'status' => $review ? 'awaiting_review' : 'rendering',
                'phase' => $review ? 'analysis' : 'generate',
            ])->save();
        });
        $run->refresh();
        if ($run->status === 'awaiting_review') {
            app(UsageLedger::class)->release($run);
        }
    }

    public function generate(MediaRun $run): void
    {
        $this->check($run);
        $keys = $run->settings['output'] === 'srt' ? ['subtitles', 'verify', 'store'] : ($run->settings['mode'] === 'dub' ? ['voice', 'subtitles', 'render', 'verify', 'store'] : ['subtitles', 'render', 'verify', 'store']);
        if ($run->settings['mode'] === 'dub' && $run->settings['keep_background'] && ! (in_array($run->settings['output'], ['mp3', 'wav'], true) && ($run->settings['audio_track'] ?? 'mix') === 'narration')) {
            array_unshift($keys, 'separate');
        }
        $existing = $run->stages ?? [];
        $stages = [];
        foreach ($keys as $key) {
            $stages[$key] = $existing[$key] ?? 'waiting';
        }
        $run->forceFill(['stages' => $stages, 'progress' => 0])->save();
        $result = $this->renderer->render(
            $run, $this->directory($run),
            fn (string $stage, bool $done = false) => $this->stage($run, $stage, $done),
            fn () => $this->cancelled($run),
        );
        $this->check($run);
        $this->stage($run, 'store');
        $storage = app(MediaStorage::class);
        $project = $run->project;
        if ($result['video']) {
            $storage->publish($project, $result['video'], 'video/mp4');
        }
        if ($result['audio'] ?? null) {
            $storage->publish($project, $result['audio'], str_ends_with($result['audio'], '.mp3') ? 'audio/mpeg' : 'audio/wav');
        }
        $storage->publish($project, $result['subtitles'], 'application/x-subrip');
        $this->stage($run, 'store', true);
        $this->check($run);
        DB::transaction(function () use ($run, $result): void {
            $current = MediaRun::lockForUpdate()->findOrFail($run->id);
            $project = MediaProject::lockForUpdate()->findOrFail($run->media_project_id);
            if ($current->cancel_requested || $project->active_run_id !== $current->id) {
                throw new MediaFailure('cancelled');
            }
            $metadata = $project->metadata;
            $metadata['output'] = $result['metadata'];
            $project->forceFill([
                'status' => 'completed', 'error_code' => null, 'output_path' => $result['video'],
                'subtitle_path' => $result['subtitles'], 'audio_path' => $result['audio'] ?? null, 'metadata' => $metadata,
            ])->save();
            $current->forceFill(['status' => 'completed', 'progress' => 100, 'finished_at' => now()])->save();
            app(RetentionCleanup::class)->published($project);
            app(UsageLedger::class)->settle($current);
        });
    }

    private function cancelled(MediaRun $run): bool
    {
        try {
            $this->check($run);

            return false;
        } catch (MediaFailure) {
            return true;
        }
    }

    private function validateTranscript(mixed $segments, float $duration): array
    {
        if (! is_array($segments) || count($segments) > 500) {
            throw new MediaFailure('provider_response_invalid');
        }
        if ($segments === []) {
            throw new MediaFailure('no_speech');
        }
        $result = [];
        $previous = 0.0;
        foreach ($segments as $segment) {
            $start = $segment['start'] ?? null;
            $end = $segment['end'] ?? null;
            $text = $segment['source_text'] ?? null;
            if (! is_numeric($start) || ! is_numeric($end) || ! is_string($text) || trim($text) === '' || mb_strlen($text) > 1500) {
                throw new MediaFailure('provider_response_invalid');
            }
            $start = (float) $start;
            $end = (float) $end;
            if (! is_finite($start) || ! is_finite($end) || $start < $previous - 0.05 || $start < 0 || $end <= $start || $end > $duration + 0.5) {
                throw new MediaFailure('provider_response_invalid');
            }
            $start = max($previous, $start);
            $end = min($duration, $end);
            if ($end - $start < 0.01) {
                throw new MediaFailure('provider_response_invalid');
            }
            $result[] = ['index' => count($result), 'start' => $start, 'end' => $end, 'source_text' => trim($text), 'text' => ''];
            $previous = $end;
        }

        return $result;
    }
}
