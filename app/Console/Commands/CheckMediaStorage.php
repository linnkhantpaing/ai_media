<?php

namespace App\Console\Commands;

use App\Services\Media\MediaStorage;
use App\Services\Media\StorageSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class CheckMediaStorage extends Command
{
    protected $signature = 'media:storage-check {--write : Verify a new small private object, then remove only that object}';

    protected $description = 'Check private media storage without revealing credentials';

    public function handle(MediaStorage $storage, StorageSettings $settings): int
    {
        if (! $storage->ready()) {
            $this->error('Media storage configuration is incomplete.');

            return self::FAILURE;
        }
        $this->info('Media disk: '.$settings->connection()['disk'].'; application limit: '.$settings->policy()['budget'].' bytes.');
        if (! $this->option('write')) {
            return self::SUCCESS;
        }
        $path = 'checks/'.Str::uuid().'.txt';
        $content = 'AI Media private storage verification '.Str::uuid();
        try {
            $disk = Storage::disk(config('filesystems.media_disk'));
            $disk->put($path, $content, ['ContentType' => 'text/plain']);
            if ($disk->size($path) !== strlen($content) || ! hash_equals(hash('sha256', $content), hash('sha256', $disk->get($path)))) {
                $this->error('Storage verification failed.');

                return self::FAILURE;
            }
            $disk->delete($path);
            if ($disk->exists($path)) {
                $this->error('Verification object cleanup failed.');

                return self::FAILURE;
            }
            $this->info('Private object write, size, SHA-256 readback, and cleanup verified.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Storage connection failed ('.$exception::class.'). Credentials were not displayed.');

            return self::FAILURE;
        }
    }
}
