<?php

namespace App\Jobs;

use App\Exceptions\MediaFailure;
use App\Models\MediaProject;
use App\Models\MediaRun;
use App\Services\CustomerAccounts;
use App\Services\Media\MediaPipeline;
use App\Services\Media\OperationsTelemetry;
use App\Services\Media\ProviderSettings;
use App\Services\UsageLedger;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessMediaRun implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 1800;

    public int $uniqueFor = 1860;

    public function __construct(public string $runId)
    {
        $this->onQueue('media');
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addDay();
    }

    public function uniqueId(): string
    {
        return $this->runId;
    }

    public function handle(MediaPipeline $pipeline): void
    {
        $run = MediaRun::find($this->runId);
        if (! $run || in_array($run->status, ['completed', 'cancelled', 'awaiting_review'], true)) {
            return;
        }
        $project = MediaProject::find($run->media_project_id);
        if (! $project || ! app(CustomerAccounts::class)->canProcess($project->user_id)) {
            $this->finish($run, 'failed', 'account_unavailable');

            return;
        }
        if (! app(UsageLedger::class)->claim($run)) {
            $this->release(10);

            return;
        }
        if (! $run->provider_revision_id) {
            MediaRun::whereKey($run->id)->whereNull('provider_revision_id')->update(['provider_revision_id' => app(ProviderSettings::class)->snapshot()]);
            $run->refresh();
        }
        app(ProviderSettings::class)->withRevision($run->provider_revision_id, function () use ($run, $pipeline): void {
            $artifacts = $run->artifacts ?? [];
            $attempt = (int) ($artifacts['processing_attempts_'.$run->phase] ?? 0) + 1;
            $artifacts['processing_attempts_'.$run->phase] = $attempt;
            $run->forceFill(['artifacts' => $artifacts])->save();
            try {
                $pipeline->check($run);
                if ($run->phase === 'analysis') {
                    $pipeline->analyze($run);
                }
                $run->refresh();
                if ($run->phase === 'generate') {
                    $pipeline->generate($run);
                }
            } catch (MediaFailure $failure) {
                $run->refresh();
                if ($failure->reason === 'cancelled' || $run->cancel_requested) {
                    $this->finish($run, 'cancelled', null);
                } elseif ($failure->retryable && $attempt < $this->tries) {
                    $run->forceFill(['status' => 'retry_waiting', 'error_code' => $failure->reason])->save();
                    MediaProject::whereKey($run->media_project_id)->where('active_run_id', $run->id)
                        ->where('status', '!=', 'cancel_requested')->update(['status' => 'queued', 'error_code' => $failure->reason]);
                    $delay = $failure->retryAfter ?? ($attempt === 1 ? 15 : 45);
                    app(OperationsTelemetry::class)->retryScheduled($run, $delay);
                    $this->release($delay);
                } else {
                    $this->finish($run, 'failed', $failure->reason);
                }
            } catch (Throwable $exception) {
                Log::error('Media run failed', ['run' => $run->id, 'exception' => $exception::class]);
                $this->finish($run, 'failed', 'processing_failed');
            }
        });
    }

    public function failed(?Throwable $exception): void
    {
        if ($run = MediaRun::find($this->runId)) {
            $this->finish($run, $run->cancel_requested ? 'cancelled' : 'failed', $run->cancel_requested ? null : 'media_timeout');
        }
    }

    private function finish(MediaRun $run, string $status, ?string $code): void
    {
        app(UsageLedger::class)->release($run);
        $run->forceFill(['status' => $status, 'error_code' => $code, 'finished_at' => now()])->save();
        MediaProject::whereKey($run->media_project_id)->where('active_run_id', $run->id)->update(['status' => $status, 'error_code' => $code]);
    }
}
