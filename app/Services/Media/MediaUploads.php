<?php

namespace App\Services\Media;

use App\Exceptions\MediaFailure;
use App\Models\MediaProject;
use App\Services\UsageLedger;
use App\Support\MediaSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MediaUploads
{
    public function initialize(int $userId, array $data): MediaProject
    {
        return Cache::lock('media-storage-reservation', 30)->block(10, fn () => $this->create($userId, $data));
    }

    private function create(int $userId, array $data): MediaProject
    {
        $data['size'] = (int) $data['size'];
        $existing = MediaProject::where('user_id', $userId)->where('client_id', $data['client_id'])->first();
        if ($existing) {
            if ($existing->source_type !== 'upload' || $existing->source_name !== $data['filename'] || $existing->expected_bytes !== $data['size']) {
                throw new MediaFailure('upload_conflict');
            }

            return $existing;
        }
        if ($data['size'] > app(StorageSettings::class)->policy()['max_bytes']) {
            throw new MediaFailure('too_large');
        }
        app(UsageLedger::class)->checkUpload($userId, $data['size'], 'local');
        app(MediaStorage::class)->reserve($data['size']);
        $disk = Storage::disk('local');
        $disk->makeDirectory('media');
        if (disk_free_space($disk->path('')) < $data['size'] + 100 * 1024 * 1024) {
            throw new MediaFailure('insufficient_storage');
        }
        $id = (string) Str::uuid();
        $extension = strtolower(pathinfo($data['filename'], PATHINFO_EXTENSION));
        $title = trim((string) ($data['title'] ?? ''));
        $title = $title === '' ? pathinfo($data['filename'], PATHINFO_FILENAME) : $title;
        $project = new MediaProject;
        $project->forceFill([
            'id' => $id, 'user_id' => $userId, 'client_id' => $data['client_id'],
            'title' => Str::limit($title, 250, ''), 'source_name' => $data['filename'],
            'source_extension' => $extension, 'source_path' => 'media/'.$id.'/source.'.$extension,
            'expected_bytes' => $data['size'], 'fingerprint' => $data['fingerprint'] ?? null,
            'settings' => MediaSettings::defaults(), 'status' => 'uploading', 'lines' => [],
            'storage_profile_id' => app(StorageSettings::class)->capture(), 'storage_policy' => app(StorageSettings::class)->policy(), 'storage_disk' => app(StorageSettings::class)->connection()['disk'],
        ])->save();
        $disk->makeDirectory('media/'.$id);

        return $project;
    }

    public function append(MediaProject $project, int $offset, string $bytes): int
    {
        if ($bytes === '' || strlen($bytes) > config('media.chunk_bytes')) {
            throw new MediaFailure('invalid_chunk');
        }

        return Cache::lock('media-upload:'.$project->id, 30)->block(5, function () use ($project, $offset, $bytes): int {
            $project->refresh();
            if ($project->status !== 'uploading') {
                throw new MediaFailure('upload_conflict');
            }
            $path = Storage::disk('local')->path($project->source_path);
            $handle = fopen($path, 'c+b');
            if ($handle === false) {
                throw new MediaFailure('insufficient_storage');
            }
            try {
                if (! flock($handle, LOCK_EX)) {
                    throw new MediaFailure('upload_conflict');
                }
                $size = fstat($handle)['size'];
                if ($offset !== $size || $size + strlen($bytes) > $project->expected_bytes) {
                    throw new MediaFailure('upload_conflict');
                }
                fseek($handle, 0, SEEK_END);
                $written = fwrite($handle, $bytes);
                fflush($handle);
                if ($written !== strlen($bytes)) {
                    ftruncate($handle, $size);
                    throw new MediaFailure('insufficient_storage');
                }
                $project->forceFill(['uploaded_bytes' => $size + $written])->save();

                return $size + $written;
            } finally {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        });
    }

    public function offset(MediaProject $project): int
    {
        if ($project->status !== 'uploading') {
            return $project->uploaded_bytes;
        }
        $path = Storage::disk('local')->path($project->source_path);
        clearstatcache(true, $path);

        return is_file($path) ? (int) filesize($path) : 0;
    }
}
