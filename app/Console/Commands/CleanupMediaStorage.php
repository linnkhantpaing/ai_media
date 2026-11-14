<?php

namespace App\Console\Commands;

use App\Jobs\RunMediaCleanup;
use App\Models\User;
use App\Services\Media\RetentionCleanup;
use App\Services\Media\StorageSettings;
use Illuminate\Console\Command;

class CleanupMediaStorage extends Command
{
    protected $signature = 'media:cleanup {--scheduled : Execute an enabled retention policy from the server scheduler}';

    protected $description = 'Preview eligible media retention files, or dispatch an enabled scheduled cleanup';

    public function handle(StorageSettings $settings, RetentionCleanup $cleanup): int
    {
        $admin = User::where('is_admin', true)->first();
        if (! $admin) {
            $this->info('No workspace administrator.');

            return self::SUCCESS;
        }
        $preview = $cleanup->preview($admin->id);
        $this->info(count($preview['files']).' eligible files; '.$preview['bytes'].' bytes.');
        if ($this->option('scheduled') && $settings->policy()['retention_enabled'] && $preview['files']) {
            $id = $cleanup->createRun($preview['files'], null);
            RunMediaCleanup::dispatch($id);
            $this->info('Retention cleanup queued.');
        }

        return self::SUCCESS;
    }
}
