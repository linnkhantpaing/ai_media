<?php

namespace App\Console\Commands;

use App\Services\AdminNotifications;
use Illuminate\Console\Command;

class SyncAdminNotifications extends Command
{
    protected $signature = 'notifications:sync';

    protected $description = 'Reconcile real Admin inbox issues without sending email or changing customer access';

    public function handle(AdminNotifications $service): int
    {
        $service->synchronize(true);
        $this->info('Admin notification issues reconciled. No email was sent.');

        return self::SUCCESS;
    }
}
