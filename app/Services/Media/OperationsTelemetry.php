<?php

namespace App\Services\Media;

use App\Jobs\ImportYouTubeVideo;
use App\Jobs\InspectMediaProject;
use App\Jobs\PrepareMediaPreview;
use App\Jobs\ProcessMediaRun;
use App\Models\MediaPreview;
use App\Models\MediaProject;
use App\Models\MediaRun;
use App\Models\MediaTask;
use App\Models\MediaWorker;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OperationsTelemetry
{
    public const TERMINAL = ['completed', 'failed', 'cancelled', 'awaiting_review'];

    public function save(MediaTask $task, array $changes): MediaTask
    {
        return DB::transaction(function () use ($task, $changes): MediaTask {
            $task->fill($changes);
            $event = (! $task->exists || $task->isDirty(['status', 'stage', 'attempts', 'error_code', 'next_retry_at'])) && ($changes['history_available'] ?? true) !== false;
            $task->save();
            if ($event) {
                DB::table('media_task_events')->insert([
                    'media_task_id' => $task->id, 'status' => $task->status, 'stage' => $task->stage,
                    'attempt' => $task->attempts, 'progress' => $task->progress,
                    'error_code' => $task->error_code, 'recorded_at' => now(),
                ]);
            }

            return $task;
        });
    }

    public function runSaved(MediaRun $run): void
    {
        $previous = $run->getRawOriginal('phase');
        if ($run->wasChanged('phase') && $previous && $previous !== $run->phase) {
            $old = MediaTask::where('source_key', 'run:'.$run->id.':'.$previous)->first();
            if ($old && in_array($old->status, ['running', 'awaiting_review'], true)) {
                $this->save($old, ['status' => 'completed', 'progress' => 100, 'finished_at' => $old->finished_at ?? now()]);
                $this->finishAttempt($old);
            }
        }
        $this->syncRun($run);
    }

    public function syncRun(MediaRun $run, bool $historical = false): MediaTask
    {
        $task = MediaTask::firstOrNew(['source_key' => 'run:'.$run->id.':'.$run->phase]);
        $status = match ($run->status) {
            'analyzing', 'translating', 'rendering' => 'running',
            'cancel_requested' => 'stopping',
            default => $run->status,
        };
        if (in_array($status, ['queued', 'retry_waiting'], true) && $task->attempt_started_at && $task->status === 'running' && ! $run->wasChanged('status')) {
            $status = 'running';
        }
        $changes = [
            'media_project_id' => $run->media_project_id, 'media_run_id' => $run->id,
            'type' => $run->phase, 'mode' => $run->settings['mode'] ?? null,
            'status' => $status, 'stage' => $run->stage, 'progress' => min(100, max(0, $run->progress)),
            'stages' => $run->stages ?? [], 'error_code' => $run->error_code,
        ];
        if (! $task->exists) {
            $changes += ['history_available' => ! $historical, 'created_at' => $run->created_at, 'max_attempts' => 3];
            if ($historical) {
                $changes['updated_at'] = $run->updated_at;
            }
        }
        if ($status === 'queued') {
            $changes += ['finished_at' => null, 'next_retry_at' => null];
        }
        if (in_array($status, self::TERMINAL, true)) {
            $changes['finished_at'] = $task->finished_at ?? $run->finished_at ?? ($historical ? null : now());
            $changes['next_retry_at'] = null;
        }
        $task = $this->save($task, $changes);
        if (! $historical && $status === 'running' && ! $task->attempt_started_at) {
            $worker = $this->worker();
            if ($worker) {
                $this->save($task, ['started_at' => $task->started_at ?? now(), 'attempt_started_at' => now(), 'media_worker_id' => $worker->id]);
                $worker->update(['current_task_id' => $task->id]);
            }
        }
        if (in_array($status, self::TERMINAL, true)) {
            $this->finishAttempt($task);
        }

        return $task->fresh();
    }

    public function syncSource(MediaProject $project, bool $historical = false): ?MediaTask
    {
        if ($project->status === 'importing') {
            $task = $this->sourceTask($project, 'import', $historical);

            return $this->save($task, [
                'status' => $project->import_cancel_requested ? 'stopping' : ($task->status === 'running' ? 'running' : 'queued'),
                'stage' => $project->import_stage ?: 'queued', 'progress' => min(99, (int) $project->source_progress), 'error_code' => $project->error_code,
                ...($historical ? ['history_available' => false] : []),
            ]);
        }
        if ($project->status === 'validating') {
            $this->finishSource($project, 'import', 'completed', null);
            $task = $this->sourceTask($project, 'validation', $historical);

            return $this->save($task, ['status' => $task->status === 'running' ? 'running' : 'queued', 'stage' => 'validate', 'progress' => min(99, (int) $project->source_progress), ...($historical ? ['history_available' => false] : [])]);
        }
        if (in_array($project->status, ['import_failed', 'import_cancelled'], true)) {
            if ($historical && ! MediaTask::where('media_project_id', $project->id)->where('type', 'import')->exists()) {
                $this->sourceTask($project, 'import', true);
            }

            return $this->finishSource($project, 'import', $project->status === 'import_failed' ? 'failed' : 'cancelled', $project->error_code, $historical);
        }
        if (in_array($project->status, ['ready', 'invalid'], true)) {
            return $this->finishSource($project, 'validation', $project->status === 'ready' ? 'completed' : 'failed', $project->error_code, $historical);
        }

        return null;
    }

    private function sourceTask(MediaProject $project, string $type, bool $historical): MediaTask
    {
        $task = MediaTask::where('media_project_id', $project->id)->where('type', $type)->whereNotIn('status', self::TERMINAL)->latest('id')->first();
        if ($task) {
            return $task;
        }

        return $this->save(new MediaTask, [
            'source_key' => $type.':'.$project->id.':'.Str::uuid(), 'media_project_id' => $project->id,
            'type' => $type, 'status' => 'queued', 'stage' => $type === 'import' ? 'queued' : 'validate',
            'history_available' => ! $historical, 'max_attempts' => 1,
            'created_at' => $historical ? $project->created_at : now(),
            'updated_at' => $historical ? $project->updated_at : now(),
        ]);
    }

    private function finishSource(MediaProject $project, string $type, string $status, ?string $error, bool $historical = false): ?MediaTask
    {
        $task = MediaTask::where('media_project_id', $project->id)->where('type', $type)->whereNotIn('status', self::TERMINAL)->latest('id')->first();
        if (! $task) {
            return null;
        }
        $this->save($task, ['status' => $status, 'stage' => $status === 'completed' ? 'verify' : $task->stage,
            'progress' => $status === 'completed' ? 100 : $task->progress, 'error_code' => $error,
            'finished_at' => $historical ? null : now(), ...($historical ? ['history_available' => false] : []),
        ]);
        $this->finishAttempt($task);

        return $task;
    }

    public function syncPreview(MediaPreview $preview): MediaTask
    {
        $task = MediaTask::firstOrNew(['source_key' => 'preview:'.$preview->id]);
        $status = $preview->cancel_requested && $preview->status === 'running' ? 'stopping' : $preview->status;

        return $this->save($task, ['media_project_id' => $preview->media_project_id, 'type' => 'preview', 'mode' => $preview->settings['mode'], 'status' => $status, 'stage' => $preview->kind === 'shorten' ? 'translate' : 'preview', 'progress' => $preview->progress, 'error_code' => $preview->error_code, 'history_available' => true, 'max_attempts' => 3, ...(in_array($status, self::TERMINAL, true) ? ['finished_at' => $task->finished_at ?? now()] : [])]);
    }

    public function payload(object|string $job): array
    {
        $task = match (true) {
            $job instanceof PrepareMediaPreview => ($preview = MediaPreview::find($job->previewId)) ? $this->syncPreview($preview) : null,
            $job instanceof ProcessMediaRun => ($run = MediaRun::find($job->runId)) ? $this->syncRun($run) : null,
            $job instanceof ImportYouTubeVideo, $job instanceof InspectMediaProject => ($project = MediaProject::find($job->projectId)) ? $this->syncSource($project) : null,
            default => null,
        };
        if (! $task) {
            return [];
        }
        $this->save($task, ['queued_at' => $task->queued_at ?? now(), 'ready_at' => now(), 'next_retry_at' => null]);

        return ['media_task_id' => $task->id];
    }

    public function started(Job $job): void
    {
        $task = MediaTask::find($job->payload()['media_task_id'] ?? null);
        if (! $task || in_array($task->status, self::TERMINAL, true)) {
            return;
        }
        $worker = $this->worker();
        $wait = $task->ready_at ? max(0, now()->timestamp - $task->ready_at->timestamp) : null;
        $this->save($task, [
            'status' => 'running', 'attempts' => $job->attempts(), 'started_at' => $task->started_at ?? now(),
            'attempt_started_at' => now(), 'next_retry_at' => null,
            'processing_seconds' => $task->processing_seconds ?? 0,
            'queue_wait_seconds' => $wait === null ? $task->queue_wait_seconds : ($task->queue_wait_seconds ?? 0) + $wait,
            'media_worker_id' => $worker?->id,
        ]);
        $worker?->update(['current_task_id' => $task->id]);
    }

    public function processed(Job $job): void
    {
        $task = MediaTask::find($job->payload()['media_task_id'] ?? null);
        if ($task?->media_run_id && ($run = MediaRun::find($task->media_run_id))) {
            $task = $this->syncRun($run);
        } elseif ($task && $task->type === 'preview' && ($preview = MediaPreview::find(substr($task->source_key, 8)))) {
            $task = $this->syncPreview($preview);
        } elseif ($task && ($project = $task->project)) {
            $this->syncSource($project->fresh());
            $task->refresh();
        }
        if ($task) {
            $this->finishAttempt($task);
        }
        $this->worker()?->update(['current_task_id' => null]);
    }

    public function retryScheduled(MediaRun $run, int $delay): void
    {
        $task = $this->syncRun($run);
        $this->save($task, ['next_retry_at' => now()->addSeconds($delay), 'ready_at' => now()->addSeconds($delay)]);
        $this->finishAttempt($task);
    }

    public function failed(Job $job): void
    {
        $task = MediaTask::find($job->payload()['media_task_id'] ?? null);
        if ($task?->media_run_id && ($run = MediaRun::find($task->media_run_id))) {
            $task = $this->syncRun($run);
        } elseif ($task && $task->type === 'preview' && ($preview = MediaPreview::find(substr($task->source_key, 8)))) {
            $task = $this->syncPreview($preview);
        } elseif ($task && $task->project) {
            $this->syncSource($task->project->fresh());
            $task->refresh();
        }
        if ($task && ! in_array($task->status, self::TERMINAL, true)) {
            $this->save($task, ['status' => 'failed', 'error_code' => 'processing_failed', 'finished_at' => now()]);
            $this->finishAttempt($task);
        }
        $this->worker()?->update(['current_task_id' => null]);
    }

    public function finishAttempt(MediaTask $task): void
    {
        if (! $task->attempt_started_at) {
            return;
        }
        $this->save($task, ['processing_seconds' => ($task->processing_seconds ?? 0) + max(0, now()->timestamp - $task->attempt_started_at->timestamp), 'attempt_started_at' => null]);
    }

    public function worker(): ?MediaWorker
    {
        $id = getenv('MEDIA_WORKER_ID');

        return $id ? MediaWorker::find($id) : null;
    }
}
