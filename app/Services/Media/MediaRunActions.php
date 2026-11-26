<?php

namespace App\Services\Media;

use App\Exceptions\MediaFailure;
use App\Jobs\ProcessMediaRun;
use App\Models\MediaProject;
use App\Models\MediaRun;
use App\Services\UsageLedger;
use Illuminate\Support\Facades\DB;

class MediaRunActions
{
    public function retry(MediaProject $project, ?string $expectedRun = null, ?string $expectedPhase = null): void
    {
        if (! ($project->metadata['audio'] ?? false)) {
            throw new MediaFailure('source_not_ready');
        }
        if (! app(MediaRuntime::class)->available()) {
            throw new MediaFailure('media_tools_missing');
        }
        $runId = DB::transaction(function () use ($project, $expectedRun, $expectedPhase): string {
            $project = MediaProject::lockForUpdate()->findOrFail($project->id);
            $run = MediaRun::lockForUpdate()->find($project->active_run_id);
            if ($project->busy()) {
                throw new MediaFailure('project_busy');
            }
            // A run the customer cancelled can be started again just like a failed one.
            if (! $run || ! in_array($run->status, ['failed', 'cancelled'], true)
                || ($expectedRun !== null && $run->id !== $expectedRun)
                || ($expectedPhase !== null && $run->phase !== $expectedPhase)) {
                throw new MediaFailure('retry_unavailable');
            }
            if (! app(ProviderSettings::class)->withRevision($run->provider_revision_id, fn (): bool => app(ProviderSettings::class)->ready())) {
                throw new MediaFailure('ai_key_missing');
            }
            app(UsageLedger::class)->reserve($run);
            $artifacts = $run->artifacts ?? [];
            $artifacts['processing_attempts_'.$run->phase] = 0;
            unset($artifacts['analysis_failure_checkpoint'], $artifacts['analysis_failures_without_progress']);
            $run->forceFill(['artifacts' => $artifacts])->save();
            $run->forceFill(['status' => 'queued', 'cancel_requested' => false, 'error_code' => null, 'finished_at' => null])->save();
            $project->forceFill(['status' => 'queued', 'error_code' => null])->save();

            return $run->id;
        });
        ProcessMediaRun::dispatch($runId)->afterCommit();
    }

    public function cancel(MediaProject $project, ?string $expectedRun = null, ?string $expectedPhase = null): void
    {
        DB::transaction(function () use ($project, $expectedRun, $expectedPhase): void {
            $project = MediaProject::lockForUpdate()->findOrFail($project->id);
            $run = MediaRun::lockForUpdate()->find($project->active_run_id);
            if (! $run || ! $project->busy() || $project->status === 'validating' || $run->cancel_requested
                || ($expectedRun !== null && $run->id !== $expectedRun)
                || ($expectedPhase !== null && $run->phase !== $expectedPhase)) {
                throw new MediaFailure('project_busy');
            }
            $queued = in_array($run->status, ['queued', 'retry_waiting'], true);
            $run->forceFill(['cancel_requested' => true, 'status' => $queued ? 'cancelled' : 'cancel_requested'])->save();
            $project->forceFill(['status' => $queued ? 'cancelled' : 'cancel_requested'])->save();
            if ($queued) {
                app(UsageLedger::class)->release($run);
            }
        });
    }
}
