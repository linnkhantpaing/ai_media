<?php

namespace App\Console\Commands;

use App\Services\AuditTrail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportAuditHistory extends Command
{
    protected $signature = 'audit:import-history';

    protected $description = 'Copy existing audit records into the safe unified journal without replaying actions';

    public function handle(AuditTrail $audit): int
    {
        foreach (AuditTrail::SOURCES as $source => $config) {
            DB::table($config[0])->orderBy('id')->chunkById(200, function ($rows) use ($audit, $source): void {
                foreach ($rows as $row) {
                    $audit->capture($source, $row->id, true);
                }
            });
        }
        DB::table('media_provider_revisions')->select(['id', 'created_by', 'created_at'])->orderBy('id')->chunkById(200, function ($rows) use ($audit): void {
            foreach ($rows as $row) {
                $audit->record('provider_revision', (string) $row->id, 'settings', 'providers.configuration_saved', 'success', $row->created_by,
                    'providers', null, 'API Keys & Models', ['revision' => $row->id], true, $row->created_at, true);
            }
        });
        $this->info('Existing audit history imported. No business actions or emails were executed.');

        return self::SUCCESS;
    }
}
