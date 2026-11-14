<?php

namespace App\Services\Media;

use App\Exceptions\MediaFailure;
use App\Models\MediaProject;
use App\Services\UsageLedger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use Throwable;

class MediaStorage
{
    public function ready(): bool
    {
        $connection = app(StorageSettings::class)->connection();

        return $connection['disk'] === 'local' || (class_exists(AwsS3V3Adapter::class) && collect(['key', 'secret', 'bucket', 'endpoint'])->every(fn (string $field): bool => trim((string) $connection[$field]) !== ''));
    }

    public function reservedBytes(): int
    {
        $sources = (int) MediaProject::sum('expected_bytes');
        $outputs = (int) DB::table('media_files')->where('is_source', false)->where('state', '!=', 'deleted')->sum('bytes');

        return $sources + $outputs;
    }

    public function reserve(int $bytes): void
    {
        if (! $this->ready()) {
            throw new MediaFailure('storage_not_configured');
        }
        if ($this->reservedBytes() + $bytes > app(StorageSettings::class)->policy()['budget']) {
            throw new MediaFailure('storage_limit');
        }
    }

    public function publish(MediaProject $project, string $relative, string $mime): void
    {
        $local = Storage::disk('local');
        $bytes = $local->size($relative);
        Cache::lock('media-storage-reservation', 30)->block(10, function () use ($project, $relative, $bytes): void {
            $query = DB::table('media_files')->where('disk', $project->storage_disk)->where('path', $relative);
            $existing = (int) ($query->value('bytes') ?? 0);
            $source = $relative === $project->source_path;
            if ($source) {
                $assignment = $project->user->currentPackageAssignment;
                $limit = $assignment?->effectiveSnapshot()['definition']['max_upload_bytes'] ?? null;
                if ($limit !== null && $bytes > $limit) {
                    throw new MediaFailure('too_large');
                }
            }
            app(UsageLedger::class)->checkStorage($project, max(0, $bytes - ($source ? max($existing, (int) $project->expected_bytes) : $existing)));
            if (! $source && $this->reservedBytes() + $bytes - $existing > app(StorageSettings::class)->policy()['budget']) {
                throw new MediaFailure('storage_limit');
            }
            DB::table('media_files')->upsert([[
                'media_project_id' => $project->id, 'disk' => $project->storage_disk, 'path' => $relative,
                'bytes' => $bytes, 'verified_at' => null, 'state' => 'active', 'is_source' => $source, 'created_at' => now(), 'updated_at' => now(),
            ]], ['disk', 'path'], ['bytes', 'state', 'verified_at', 'updated_at']);
        });
        if ($project->storage_disk === 'local') {
            DB::table('media_files')->where('media_project_id', $project->id)->where('path', $relative)->update(['verified_at' => now()]);
            app(UsageLedger::class)->syncStorage($project);

            return;
        }
        $local = Storage::disk('local');
        $stream = null;
        try {
            $target = app(StorageSettings::class)->filesystem($project->storage_profile_id, $project->storage_disk);
            $stream = $local->readStream($relative);
            if (! is_resource($stream) || ! $target->put($relative, $stream, ['ContentType' => $mime])) {
                throw new MediaFailure('storage_unavailable', true);
            }
            if ($target->size($relative) !== $local->size($relative)) {
                throw new MediaFailure('storage_verification_failed', true);
            }
            DB::table('media_files')->where('media_project_id', $project->id)->where('path', $relative)->update(['verified_at' => now()]);
            app(UsageLedger::class)->syncStorage($project);
        } catch (MediaFailure $failure) {
            throw $failure;
        } catch (Throwable) {
            throw new MediaFailure('storage_unavailable', true);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public function materialize(MediaProject $project, string $relative): string
    {
        if (! str_starts_with($relative, 'media/'.$project->id.'/') || str_contains($relative, '..') || str_contains($relative, '\\')) {
            throw new MediaFailure('source_missing');
        }
        if ($project->artifacts_expires_at?->isPast() && app(StorageSettings::class)->policy()['retention_enabled'] && ! app(RetentionCleanup::class)->protected($project)) {
            throw new MediaFailure('media_expired');
        }
        $file = DB::table('media_files')->where('media_project_id', $project->id)->where('path', $relative)->first();
        if ($file && ($file->state !== 'active' || ($file->expires_at && app(StorageSettings::class)->policy()['retention_enabled'] && ! app(RetentionCleanup::class)->protected($project) && Carbon::parse($file->expires_at)->isPast()))) {
            throw new MediaFailure('source_missing');
        }
        if (Storage::disk('local')->exists($relative)) {
            return Storage::disk('local')->path($relative);
        }

        return Cache::lock('media-cache:'.hash('sha256', $relative), 960)->block(10, fn () => $this->restore($project, $relative));
    }

    private function restore(MediaProject $project, string $relative): string
    {
        $local = Storage::disk('local');
        if ($local->exists($relative)) {
            return $local->path($relative);
        }
        if ($project->storage_disk === 'local') {
            throw new MediaFailure('source_missing');
        }
        $stream = null;
        try {
            $stream = app(StorageSettings::class)->filesystem($project->storage_profile_id, $project->storage_disk)->readStream($relative);
            if (! is_resource($stream) || ! $local->put($relative.'.partial', $stream)) {
                throw new MediaFailure('storage_unavailable', true);
            }
            if ($local->size($relative.'.partial') !== app(StorageSettings::class)->filesystem($project->storage_profile_id, $project->storage_disk)->size($relative)) {
                throw new MediaFailure('storage_verification_failed', true);
            }
            $local->move($relative.'.partial', $relative);
        } catch (Throwable) {
            throw new MediaFailure('storage_unavailable', true);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return $local->path($relative);
    }

    public function discard(MediaProject $project): void
    {
        try {
            if ($project->storage_disk !== 'local') {
                $disk = app(StorageSettings::class)->filesystem($project->storage_profile_id, $project->storage_disk);
                $disk->deleteDirectory('media/'.$project->id);
                if ($disk->directoryExists('media/'.$project->id)) {
                    throw new MediaFailure('storage_unavailable', true);
                }
            }
            Storage::disk('local')->deleteDirectory('media/'.$project->id);
            if (Storage::disk('local')->directoryExists('media/'.$project->id)) {
                throw new MediaFailure('storage_unavailable', true);
            }
            app(UsageLedger::class)->discardStorage($project);
        } catch (Throwable) {
            throw new MediaFailure('storage_unavailable', true);
        }
    }
}
