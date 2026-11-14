<?php

namespace App\Services\Media;

use App\Exceptions\MediaFailure;
use App\Jobs\ImportYouTubeVideo;
use App\Jobs\InspectMediaProject;
use App\Models\MediaProject;
use App\Models\MediaTask;
use App\Models\User;
use App\Services\UsageLedger;
use App\Support\MediaSettings;
use App\Support\YouTubeUrl;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class YouTubeImports
{
    public function initialize(int $userId, array $data): MediaProject
    {
        $url = YouTubeUrl::normalize($data['url']) ?? throw new MediaFailure('invalid_youtube_url');

        return Cache::lock('media-storage-reservation', 30)->block(10, function () use ($userId, $data, $url): MediaProject {
            $existing = MediaProject::where('user_id', $userId)->where('client_id', $data['client_id'])->first();
            if ($existing) {
                if ($existing->source_type !== 'youtube' || $existing->source_url !== $url) {
                    throw new MediaFailure('upload_conflict');
                }

                return $existing;
            }
            if (! app(YouTubeDownloader::class)->available()) {
                throw new MediaFailure('youtube_tools_missing');
            }
            $policy = app(StorageSettings::class)->policy();
            $assignment = User::findOrFail($userId)->currentPackageAssignment;
            $reserve = (int) $policy['max_bytes'];
            if ($assignment) {
                $definition = $assignment->effectiveSnapshot()['definition'];
                $reserve = min($reserve, (int) ($definition['max_upload_bytes'] ?? $reserve), max(0, (int) $definition['storage_bytes'] - app(UsageLedger::class)->allocatedStorage($userId)));
                $policy['max_duration'] = min($policy['max_duration'], $definition['max_video_seconds']);
            }
            if ($reserve < 1) {
                throw new MediaFailure('storage_limit');
            }
            app(UsageLedger::class)->checkUpload($userId, $reserve, 'youtube');
            $policy['max_bytes'] = $reserve;
            app(MediaStorage::class)->reserve($reserve);
            $disk = Storage::disk('local');
            $disk->makeDirectory('media');
            if (disk_free_space($disk->path('')) < 2 * $reserve + 100 * 1024 * 1024) {
                throw new MediaFailure('insufficient_storage');
            }
            $id = (string) Str::uuid();
            $project = new MediaProject;
            $project->forceFill([
                'id' => $id, 'user_id' => $userId, 'client_id' => $data['client_id'],
                'title' => trim((string) ($data['title'] ?? '')) ?: 'YouTube video',
                'source_name' => 'YouTube video.mp4', 'source_extension' => 'mp4',
                'source_path' => 'media/'.$id.'/source.mp4', 'source_type' => 'youtube', 'source_url' => $url,
                'expected_bytes' => $reserve, 'uploaded_bytes' => 0, 'status' => 'importing', 'import_stage' => 'queued',
                'settings' => MediaSettings::defaults(), 'lines' => [], 'storage_profile_id' => app(StorageSettings::class)->capture(), 'storage_policy' => $policy, 'storage_disk' => app(StorageSettings::class)->connection()['disk'],
            ])->save();
            $disk->makeDirectory('media/'.$id.'/import');

            return $project;
        });
    }

    public function retry(MediaProject $project, ?int $expectedTask = null): void
    {
        Cache::lock('media-storage-reservation', 30)->block(10, function () use ($project, $expectedTask): void {
            DB::transaction(function () use ($project, $expectedTask): void {
                $current = MediaProject::lockForUpdate()->findOrFail($project->id);
                if ($expectedTask !== null && (int) MediaTask::where('media_project_id', $current->id)->where('type', 'import')->latest('id')->value('id') !== $expectedTask) {
                    throw new MediaFailure('project_busy');
                }
                if ($current->source_type !== 'youtube' || ! in_array($current->status, ['import_failed', 'import_cancelled', 'invalid'], true)) {
                    throw new MediaFailure('project_busy');
                }
                if ($current->status === 'invalid' && Storage::disk('local')->exists($current->source_path)) {
                    $current->forceFill(['status' => 'validating', 'import_stage' => 'validating', 'error_code' => null])->save();
                    InspectMediaProject::dispatch($current->id)->afterCommit();

                    return;
                }
                $maximum = (int) ($current->storage_policy ?? app(StorageSettings::class)->policy())['max_bytes'];
                app(MediaStorage::class)->reserve(max(0, $maximum - $current->expected_bytes));
                $current->forceFill(['expected_bytes' => $maximum, 'status' => 'importing', 'import_stage' => 'queued', 'import_cancel_requested' => false, 'error_code' => null])->save();
                ImportYouTubeVideo::dispatch($current->id)->afterCommit();
            });
        });
    }

    public function cancel(MediaProject $project, ?int $expectedTask = null): void
    {
        DB::transaction(function () use ($project, $expectedTask): void {
            $current = MediaProject::lockForUpdate()->findOrFail($project->id);
            if ($expectedTask !== null && (int) MediaTask::where('media_project_id', $current->id)->where('type', 'import')->latest('id')->value('id') !== $expectedTask) {
                throw new MediaFailure('project_busy');
            }
            if ($current->source_type !== 'youtube' || $current->status !== 'importing') {
                throw new MediaFailure('project_busy');
            }
            $queued = $current->import_stage === 'queued';
            $current->forceFill([
                'import_cancel_requested' => true, 'import_stage' => $queued ? 'cancelled' : 'stopping',
                'status' => $queued ? 'import_cancelled' : 'importing',
                'expected_bytes' => $queued ? 0 : $current->expected_bytes,
            ])->save();
        });
    }
}
