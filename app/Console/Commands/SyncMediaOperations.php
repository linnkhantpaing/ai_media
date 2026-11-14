<?php

namespace App\Console\Commands;

use App\Models\MediaProject;
use App\Models\MediaRun;
use App\Models\MediaTask;
use App\Services\Media\OperationsTelemetry;
use Illuminate\Console\Command;

class SyncMediaOperations extends Command
{
    protected $signature = 'media:operations-sync';

    protected $description = 'Index existing real media runs without inventing historical attempts or worker telemetry';

    public function handle(OperationsTelemetry $telemetry): int
    {
        $runs = 0;
        foreach (MediaRun::cursor() as $run) {
            if (! MediaTask::where('source_key', 'run:'.$run->id.':'.$run->phase)->exists()) {
                $telemetry->syncRun($run, true);
                $runs++;
            }
        }
        foreach (MediaProject::whereIn('status', ['importing', 'validating', 'import_failed', 'import_cancelled'])->cursor() as $project) {
            $telemetry->syncSource($project, true);
        }
        $this->info('Indexed '.$runs.' existing run records. Unknown timing/attempt history remains unreported.');

        return self::SUCCESS;
    }
}
