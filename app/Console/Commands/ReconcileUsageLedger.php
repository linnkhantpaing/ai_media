<?php

namespace App\Console\Commands;

use App\Models\MediaProject;
use App\Models\MediaRun;
use App\Models\UsageRecord;
use App\Services\UsageLedger;
use Illuminate\Console\Command;

class ReconcileUsageLedger extends Command
{
    protected $signature = 'usage:reconcile';

    protected $description = 'Reconcile verified storage and terminal processing reservations without inventing historical charges';

    public function handle(UsageLedger $ledger): int
    {
        MediaProject::query()->chunkById(100, function ($projects) use ($ledger): void {
            foreach ($projects as $project) {
                $ledger->syncStorage($project, true);
            }
        });
        UsageRecord::where('status', 'reserved')->where('kind', 'processing')->chunkById(100, function ($records) use ($ledger): void {
            foreach ($records as $record) {
                $run = MediaRun::find($record->media_run_id);
                if ($run?->status === 'completed') {
                    $ledger->settle($run);
                } elseif ($run && in_array($run->status, ['failed', 'cancelled', 'awaiting_review'], true)) {
                    $ledger->release($run);
                }
            }
        });
        $this->info('Verified storage and terminal reservations reconciled.');

        return self::SUCCESS;
    }
}
