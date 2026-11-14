<?php

namespace App\Providers;

use App\Models\MediaPreview;
use App\Models\MediaProject;
use App\Models\MediaRun;
use App\Services\Media\AutomaticMediaWorker;
use App\Services\Media\OperationsTelemetry;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

class MediaOperationsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        MediaPreview::saved(fn (MediaPreview $preview) => app(OperationsTelemetry::class)->syncPreview($preview));
        MediaRun::saved(fn (MediaRun $run) => app(OperationsTelemetry::class)->runSaved($run));
        MediaProject::saved(fn (MediaProject $project) => app(OperationsTelemetry::class)->syncSource($project));
        Queue::createPayloadUsing(function (string $connection, ?string $queue, array $payload): array {
            $command = $payload['data']['command'] ?? null;

            return is_object($command) || is_string($command) ? app(OperationsTelemetry::class)->payload($command) : [];
        });
        Event::listen(JobQueued::class, function (JobQueued $event): void {
            if (in_array($event->queue, config('operations.queues'), true) && $event->connectionName === config('queue.default')) {
                DB::afterCommit(fn () => app(AutomaticMediaWorker::class)->wake());
            }
        });
        Event::listen(JobProcessing::class, fn (JobProcessing $event) => app(OperationsTelemetry::class)->started($event->job));
        Event::listen(JobProcessed::class, fn (JobProcessed $event) => app(OperationsTelemetry::class)->processed($event->job));
        Event::listen(JobFailed::class, fn (JobFailed $event) => app(OperationsTelemetry::class)->failed($event->job));
    }
}
