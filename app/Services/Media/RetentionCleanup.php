<?php

namespace App\Services\Media;

use App\Models\MediaProject;
use App\Services\UsageLedger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class RetentionCleanup
{
    public function protected(MediaProject $project): bool
    {
        return $project->busy() || ! in_array($project->status, ['completed', 'failed', 'cancelled', 'invalid', 'import_failed', 'import_cancelled'], true);
    }

    public function published(MediaProject $project, bool $renew = true): void
    {
        $policy = $project->storage_policy ?? app(StorageSettings::class)->policy();
        if (! $policy['retention_enabled']) {
            return;
        }
        $finish = $renew ? now() : ($project->runs()->where('status', 'completed')->latest('finished_at')->value('finished_at') ?? $project->updated_at);
        $anchor = Carbon::parse($finish);
        $source = $anchor->copy()->addDays($policy['source_days']);
        $artifacts = $anchor->copy()->addDays($policy['artifact_days']);
        $output = $anchor->copy()->addDays($policy['keep_days']);
        $project->update(['source_expires_at' => $source, 'artifacts_expires_at' => $artifacts]);
        DB::table('media_files')->where('media_project_id', $project->id)->where('state', '!=', 'deleted')->where('is_source', true)->update(['expires_at' => $source]);
        $query = DB::table('media_files')->where('media_project_id', $project->id)->where('state', '!=', 'deleted')->where('is_source', false);
        if ($renew) {
            $query->whereIn('path', array_filter([$project->output_path, $project->audio_path, $project->subtitle_path]));
        }
        $query->update(['expires_at' => $output]);
        DB::table('media_files')->where('media_project_id', $project->id)->where('path', 'media/'.$project->id.'/poster.jpg')->update(['expires_at' => $artifacts]);
    }

    public function eligible(object $file, MediaProject $project): bool
    {
        return app(StorageSettings::class)->policy()['retention_enabled'] && ($project->storage_policy ?? app(StorageSettings::class)->policy())['retention_enabled'] && ! $this->protected($project) && $file->state !== 'deleted' && $file->expires_at && Carbon::parse($file->expires_at)->isPast() && str_starts_with($file->path, 'media/'.$project->id.'/') && ! str_contains($file->path, '..') && ! str_contains($file->path, '\\');
    }

    private function cacheEligible(object $file, MediaProject $project): bool
    {
        $policy = $project->storage_policy ?? app(StorageSettings::class)->policy();
        $local = Storage::disk('local');

        return app(StorageSettings::class)->policy()['retention_enabled'] && $policy['retention_enabled'] && $policy['cleanup_cache'] && ! $this->protected($project)
            && $project->storage_disk !== 'local' && $file->state === 'active' && $file->verified_at
            && str_starts_with($file->path, 'media/'.$project->id.'/') && ! str_contains($file->path, '..')
            && ! str_contains($file->path, '\\') && $local->exists($file->path)
            && $local->lastModified($file->path) < now()->subHours($policy['cache_hours'])->timestamp;
    }

    public function preview(int $user): array
    {
        $candidates = [];
        $skipped = 0;
        foreach (DB::table('media_files')->where('state', '!=', 'deleted')->whereNotNull('expires_at')->where('expires_at', '<=', now())->orderBy('id')->limit(5000)->get() as $file) {
            $project = MediaProject::find($file->media_project_id);
            if (! $project) {
                continue;
            }if (! $this->eligible($file, $project)) {
                $skipped++;

                continue;
            }$candidates[] = ['id' => (int) $file->id, 'project' => $project->title, 'projectId' => $project->id, 'kind' => $file->is_source ? 'source' : 'output', 'bytes' => (int) $file->bytes, 'expiresAt' => Carbon::parse($file->expires_at)->toIso8601String()];
        }
        $ids = array_column($candidates, 'id');
        if (app(StorageSettings::class)->policy()['retention_enabled']) {
            foreach (DB::table('media_files')->where('state', 'active')->whereNotNull('verified_at')->limit(5000)->get() as $file) {
                $project = MediaProject::find($file->media_project_id);
                if ($project && ! in_array((int) $file->id, $ids, true) && $this->cacheEligible($file, $project)) {
                    $candidates[] = ['id' => (int) $file->id, 'project' => $project->title, 'projectId' => $project->id, 'kind' => 'cache', 'bytes' => (int) $file->bytes, 'expiresAt' => $file->updated_at];
                }
            }
            foreach (MediaProject::whereNotNull('source_expired_at')->where('artifacts_expires_at', '<=', now())->get() as $project) {
                if ($this->protected($project) || DB::table('media_files')->where('media_project_id', $project->id)->where('state', '!=', 'deleted')->exists()) {
                    continue;
                }
                $local = Storage::disk('local');
                $directory = 'media/'.$project->id;
                if (! $local->directoryExists($directory)) {
                    continue;
                }
                $bytes = 0;
                foreach ($local->allFiles($directory) as $path) {
                    $bytes += $local->size($path);
                }
                $candidates[] = ['id' => 'artifacts:'.$project->id, 'project' => $project->title, 'projectId' => $project->id, 'kind' => 'artifacts', 'bytes' => $bytes, 'expiresAt' => $project->artifacts_expires_at->toIso8601String()];
            }
        }
        $id = (string) Str::uuid();
        $revision = (int) DB::table('media_storage_settings')->where('id', 1)->value('revision');
        DB::table('media_cleanup_previews')->insert(['id' => $id, 'user_id' => $user, 'revision' => $revision, 'candidates' => json_encode($candidates, JSON_THROW_ON_ERROR), 'expires_at' => now()->addMinutes(10), 'created_at' => now()]);

        return ['id' => $id, 'files' => $candidates, 'bytes' => array_sum(array_column($candidates, 'bytes')), 'skipped' => $skipped, 'expiresAt' => now()->addMinutes(10)->toIso8601String(), 'revision' => $revision];
    }

    public function confirm(string $preview, int $user): string
    {
        return DB::transaction(function () use ($preview, $user): string {
            $record = DB::table('media_cleanup_previews')->where('id', $preview)->where('user_id', $user)->lockForUpdate()->first();
            abort_unless($record && ! $record->used_at && Carbon::parse($record->expires_at)->isFuture() && (int) $record->revision === (int) DB::table('media_storage_settings')->where('id', 1)->value('revision'), 409, 'cleanup_preview_expired');
            DB::table('media_cleanup_previews')->where('id', $preview)->update(['used_at' => now()]);

            return $this->createRun(json_decode($record->candidates, true, 512, JSON_THROW_ON_ERROR), $user);
        });
    }

    public function createRun(array $candidates, ?int $user): string
    {
        $id = (string) Str::uuid();
        DB::table('media_cleanup_runs')->insert(['id' => $id, 'user_id' => $user, 'status' => 'queued', 'candidates' => json_encode($candidates, JSON_THROW_ON_ERROR), 'created_at' => now()]);

        return $id;
    }

    public function execute(string $id): void
    {
        Cache::lock('media-cleanup-run:'.$id, 1900)->get(function () use ($id): void {
            $run = DB::table('media_cleanup_runs')->find($id);
            if (! $run || $run->status === 'completed') {
                return;
            }
            DB::table('media_cleanup_runs')->where('id', $id)->update(['status' => 'running']);
            $results = json_decode($run->results ?? '[]', true);
            $bytes = (int) $run->reclaimed_bytes;
            foreach (json_decode($run->candidates, true, 512, JSON_THROW_ON_ERROR) as $candidate) {
                if (collect($results)->contains(fn (array $r) => $r['id'] === $candidate['id'] && $r['status'] === 'deleted')) {
                    continue;
                }
                $results = array_values(array_filter($results, fn (array $r) => $r['id'] !== $candidate['id']));
                $outcome = 'skipped';
                try {
                    Cache::lock('media-upload:'.$candidate['projectId'], 60)->block(5, function () use ($candidate, &$outcome, &$bytes): void {
                        DB::transaction(function () use ($candidate, &$outcome, &$bytes): void {
                            $project = MediaProject::lockForUpdate()->find($candidate['projectId']);
                            if (! $project) {
                                return;
                            }
                            if (($candidate['kind'] ?? '') === 'artifacts') {
                                if (! app(StorageSettings::class)->policy()['retention_enabled'] || $this->protected($project) || ! $project->source_expired_at || ! $project->artifacts_expires_at?->isPast() || DB::table('media_files')->where('media_project_id', $project->id)->where('state', '!=', 'deleted')->exists()) {
                                    return;
                                }
                                $directory = 'media/'.$project->id;
                                $local = Storage::disk('local');
                                if (! Str::isUuid($project->id)) {
                                    return;
                                }
                                if (! $local->deleteDirectory($directory) || $local->directoryExists($directory)) {
                                    throw new \RuntimeException('cache_delete');
                                }
                                $bytes += (int) $candidate['bytes'];
                                $outcome = 'deleted';

                                return;
                            }
                            $file = DB::table('media_files')->where('id', $candidate['id'])->lockForUpdate()->first();
                            if ($file && ($candidate['kind'] ?? '') === 'cache') {
                                if (! $this->cacheEligible($file, $project)) {
                                    return;
                                }
                                $remote = app(StorageSettings::class)->filesystem($project->storage_profile_id, $project->storage_disk);
                                if (! $remote->exists($file->path) || $remote->size($file->path) !== (int) $file->bytes) {
                                    throw new \RuntimeException('cache_unverified');
                                }
                                $local = Storage::disk('local');
                                $local->delete($file->path);
                                if ($local->exists($file->path)) {
                                    throw new \RuntimeException('cache_delete');
                                }
                                $outcome = 'deleted';
                                $bytes += (int) $candidate['bytes'];

                                return;
                            }
                            if (! $project || ! $file || $file->media_project_id !== $project->id || ! $this->eligible($file, $project)) {
                                return;
                            }
                            DB::table('media_files')->where('id', $file->id)->update(['state' => 'pending_delete']);
                            try {
                                $disk = app(StorageSettings::class)->filesystem($project->storage_profile_id, $project->storage_disk);
                                if ($project->storage_disk !== 'local') {
                                    $disk->delete($file->path);
                                    if ($disk->exists($file->path)) {
                                        throw new \RuntimeException('remote_delete');
                                    }
                                }
                                $local = Storage::disk('local');
                                $local->delete($file->path);
                                if ($local->exists($file->path)) {
                                    throw new \RuntimeException('local_delete');
                                }
                                DB::table('media_files')->where('id', $file->id)->update(['state' => 'deleted', 'updated_at' => now()]);
                                app(UsageLedger::class)->syncStorage($project);
                                foreach (['output_path', 'audio_path', 'subtitle_path'] as $field) {
                                    if ($file->path === $project->$field) {
                                        $project->$field = null;
                                    }
                                }
                                if ($file->is_source) {
                                    $project->expected_bytes = 0;
                                    $project->source_expired_at = now();
                                    $project->error_code = 'media_expired';
                                }
                                if ($file->path === 'media/'.$project->id.'/poster.jpg') {
                                    $metadata = $project->metadata ?? [];
                                    $metadata['poster'] = false;
                                    $project->metadata = $metadata;
                                }
                                $project->save();
                                $bytes += (int) $file->bytes;
                                $outcome = 'deleted';
                            } catch (Throwable) {
                                $outcome = 'failed';
                            }
                        });
                    });
                } catch (Throwable) {
                    $outcome = 'failed';
                }
                $results[] = ['id' => $candidate['id'], 'projectId' => $candidate['projectId'], 'status' => $outcome];
                DB::table('media_cleanup_runs')->where('id', $id)->update(['results' => json_encode($results, JSON_THROW_ON_ERROR), 'reclaimed_bytes' => $bytes]);
            }
            $failed = count(array_filter($results, fn (array $r) => $r['status'] === 'failed'));
            DB::table('media_cleanup_runs')->where('id', $id)->update(['status' => $failed ? 'partial' : 'completed', 'finished_at' => now()]);
            Cache::forget('media-local-usage');
        });
    }

    public function automation(): array
    {
        $heartbeat = Cache::get('media-cleanup-scheduler');
        $enabled = app(StorageSettings::class)->policy()['retention_enabled'];

        return ['state' => ! $enabled ? 'disabled' : ($heartbeat && Carbon::parse($heartbeat)->gt(now()->subMinutes(3)) ? 'ready' : 'setup_required'), 'lastHeartbeat' => $heartbeat];
    }

    public function history(): array
    {
        return DB::table('media_cleanup_runs')->latest('created_at')->limit(20)->get(['id', 'status', 'reclaimed_bytes', 'results', 'created_at', 'finished_at'])->map(fn ($r) => ['id' => $r->id, 'status' => $r->status, 'bytes' => (int) $r->reclaimed_bytes, 'results' => json_decode($r->results ?? '[]', true), 'createdAt' => Carbon::parse($r->created_at)->toIso8601String(), 'finishedAt' => $r->finished_at ? Carbon::parse($r->finished_at)->toIso8601String() : null])->all();
    }
}
