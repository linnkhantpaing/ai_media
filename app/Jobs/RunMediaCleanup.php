<?php

namespace App\Jobs;

use App\Services\Media\RetentionCleanup;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class RunMediaCleanup implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(public string $runId)
    {
        $this->onQueue('media');
    }

    public function failed(?\Throwable $exception): void
    {
        $row = DB::table('media_cleanup_runs')->find($this->runId);
        if (! $row) {
            return;
        }
        $results = json_decode($row->results ?? '[]', true);
        $known = array_column($results, 'id');
        foreach (json_decode($row->candidates, true) as $candidate) {
            if (! in_array($candidate['id'], $known, true)) {
                $results[] = ['id' => $candidate['id'], 'projectId' => $candidate['projectId'], 'status' => 'failed'];
            }
        }
        DB::table('media_cleanup_runs')->where('id', $this->runId)->update(['status' => 'failed', 'results' => json_encode($results, JSON_THROW_ON_ERROR), 'finished_at' => now()]);
    }

    public function handle(RetentionCleanup $cleanup): void
    {
        $cleanup->execute($this->runId);
    }
}
