<?php

namespace App\Jobs;

use App\Exceptions\MediaFailure;
use App\Models\MediaProject;
use App\Services\CustomerAccounts;
use App\Services\Media\MediaRuntime;
use App\Services\Media\OperationsTelemetry;
use App\Services\Media\StorageSettings;
use App\Services\Media\YouTubeDownloader;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class ImportYouTubeVideo implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1500;

    public int $uniqueFor = 1800;

    public function __construct(public string $projectId)
    {
        $this->onQueue('media-inspect');
    }

    public function uniqueId(): string
    {
        return $this->projectId;
    }

    public function handle(YouTubeDownloader $downloader, MediaRuntime $runtime): void
    {
        $project = MediaProject::find($this->projectId);
        app(StorageSettings::class)->withPolicy($project?->storage_policy, fn () => $this->perform($downloader, $runtime));
    }

    private function perform(YouTubeDownloader $downloader, MediaRuntime $runtime): void
    {
        $project = MediaProject::find($this->projectId);
        if (! $project || $project->source_type !== 'youtube' || $project->status !== 'importing') {
            return;
        }
        if (! app(CustomerAccounts::class)->canProcess($project->user_id)) {
            $project->update(['status' => 'failed', 'error_code' => 'account_unavailable']);

            return;
        }
        $disk = Storage::disk('local');
        $relative = 'media/'.$project->id.'/import';
        $disk->makeDirectory($relative);
        $directory = $disk->path($relative);
        $cancelled = fn (): bool => $this->cancelled();
        try {
            $this->stage('checking');
            $info = $downloader->inspect($project->source_url, $directory, $cancelled);
            if ($this->cancelled()) {
                throw new MediaFailure('cancelled');
            }
            if ($project->title === 'YouTube video') {
                MediaProject::whereKey($project->id)->where('title', 'YouTube video')->update(['title' => Str::limit(trim($info['title']), 250, '') ?: 'YouTube video']);
            }
            $this->stage('downloading');
            $download = $downloader->download($project->source_url, $directory, function (int $bytes): void {
                MediaProject::whereKey($this->projectId)->where('status', 'importing')->where('import_cancel_requested', false)->update(['imported_bytes' => max(0, $bytes)]);
            }, $cancelled);
            $this->stage('preparing');
            $probe = $runtime->probe($download);
            $video = collect($probe['streams'] ?? [])->firstWhere('codec_type', 'video');
            $audio = collect($probe['streams'] ?? [])->firstWhere('codec_type', 'audio');
            if (! $video || ! $audio) {
                throw new MediaFailure($audio ? 'invalid_media' : 'no_audio');
            }
            $copy = ($video['codec_name'] ?? '') === 'h264' && ($video['pix_fmt'] ?? '') === 'yuv420p' && ($audio['codec_name'] ?? '') === 'aac';
            $temporary = $disk->path('media/'.$project->id.'/source.partial.mp4');
            $arguments = ['-nostdin', '-v', 'error', '-y', '-protocol_whitelist', 'file,pipe', '-i', $download, '-map', '0:v:0', '-map', '0:a:0'];
            array_push($arguments, ...($copy ? ['-c', 'copy'] : ['-c:v', 'libx264', '-preset', 'veryfast', '-crf', '21', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-threads', '2']));
            array_push($arguments, '-movflags', '+faststart', '-map_metadata', '-1', $temporary);
            $runtime->execute('ffmpeg', $arguments, timeout: 600, cancelled: $cancelled);
            clearstatcache(true, $temporary);
            $size = filesize($temporary);
            if (! $size || $size > app(StorageSettings::class)->limits()['max_bytes']) {
                throw new MediaFailure('too_large');
            }
            if ($this->cancelled()) {
                throw new MediaFailure('cancelled');
            }
            rename($temporary, $disk->path($project->source_path));
            Cache::lock('media-storage-reservation', 30)->block(10, function () use ($project, $size, $info): void {
                DB::transaction(function () use ($project, $size, $info): void {
                    $current = MediaProject::lockForUpdate()->findOrFail($project->id);
                    if ($current->import_cancel_requested || $current->status !== 'importing') {
                        throw new MediaFailure('cancelled');
                    }
                    $filename = trim((string) preg_replace('/[\\\\\/\x00-\x1F]+/u', ' ', $info['title'])) ?: 'YouTube video';
                    $current->forceFill([
                        'source_name' => Str::limit($filename, 240, '').'.mp4', 'source_mime' => 'video/mp4',
                        'expected_bytes' => $size, 'uploaded_bytes' => $size, 'status' => 'validating',
                        'import_stage' => 'validating', 'imported_bytes' => max($size, $current->imported_bytes), 'error_code' => null,
                    ])->save();
                });
            });
            InspectMediaProject::dispatch($project->id)->afterCommit();
        } catch (MediaFailure $failure) {
            $this->finish($failure->reason === 'cancelled' ? 'import_cancelled' : 'import_failed', $failure->reason === 'cancelled' ? null : $failure->reason);
        } catch (Throwable $exception) {
            Log::error('YouTube import failed', ['project' => $project->id, 'exception' => $exception::class]);
            $this->finish('import_failed', 'youtube_unavailable');
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->finish('import_failed', 'youtube_timeout');
    }

    private function cancelled(): bool
    {
        $project = MediaProject::find($this->projectId);

        return ! $project || $project->status !== 'importing' || $project->import_cancel_requested;
    }

    private function stage(string $stage): void
    {
        if ($this->cancelled()) {
            throw new MediaFailure('cancelled');
        }
        MediaProject::whereKey($this->projectId)->where('status', 'importing')->where('import_cancel_requested', false)->update(['import_stage' => $stage]);
        if ($project = MediaProject::find($this->projectId)) {
            app(OperationsTelemetry::class)->syncSource($project);
        }
    }

    private function finish(string $status, ?string $error): void
    {
        $project = MediaProject::find($this->projectId);
        if (! $project || $project->status !== 'importing') {
            return;
        }
        $retained = 0;
        foreach (Storage::disk('local')->allFiles('media/'.$project->id) as $file) {
            $retained += Storage::disk('local')->size($file);
        }
        $project->forceFill([
            'status' => $status, 'import_stage' => $status === 'import_cancelled' ? 'cancelled' : 'failed',
            'error_code' => $error, 'expected_bytes' => min((int) app(StorageSettings::class)->limits()['max_bytes'], $retained),
        ])->save();
    }
}
