<?php

namespace App\Services\Media;

use App\Models\MediaTask;
use App\Models\MediaWorker;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class OperationsMonitor
{
    public const STATUSES = ['queued', 'running', 'retry_waiting', 'awaiting_review', 'completed', 'failed', 'cancelled'];

    public const STAGES = ['prepare', 'preparing', 'transcribe', 'context', 'translate', 'voice', 'separate', 'preview', 'subtitles', 'render', 'rendering', 'verify', 'store', 'queued', 'checking', 'downloading', 'preparing', 'validate', 'validating', 'failed', 'cancelled', 'stopping'];

    public function browse(Request $request): array
    {
        $input = $request->validate([
            'q' => ['nullable', 'string', 'max:250'], 'status' => ['nullable', Rule::in(self::STATUSES)],
            'type' => ['nullable', Rule::in(['import', 'validation', 'analysis', 'generate', 'preview'])],
            'mode' => ['nullable', Rule::in(['subs', 'dub'])],
            'date' => ['nullable', Rule::in(['today', 'week', 'custom'])],
            'from' => ['nullable', 'required_if:date,custom', 'date_format:Y-m-d'],
            'to' => ['nullable', 'required_if:date,custom', 'date_format:Y-m-d', 'after_or_equal:from'],
            'sort' => ['nullable', Rule::in(['newest', 'oldest', 'queued'])],
            'view' => ['nullable', Rule::in(['table', 'cards'])], 'page' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ]);
        $filters = array_replace(array_fill_keys(['q', 'status', 'type', 'mode', 'date', 'from', 'to'], ''), ['sort' => 'newest', 'view' => 'table'], array_filter($input, fn ($value, $key): bool => $value !== null && $key !== 'page', ARRAY_FILTER_USE_BOTH));
        $filters['q'] = trim($filters['q']);
        $query = MediaTask::query()->whereHas('project');
        if ($filters['q'] !== '') {
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['q']).'%';
            $query->where(function ($query) use ($term, $filters): void {
                $query->whereHas('project', fn ($project) => $project->whereRaw("(title LIKE ? ESCAPE '!' OR source_name LIKE ? ESCAPE '!')", [$term, $term]));
                if (preg_match('/^J-([0-9]{1,12})$/i', $filters['q'], $matches)) {
                    $query->orWhere('id', (int) $matches[1]);
                }
            });
        }
        foreach (['type', 'mode'] as $field) {
            if ($filters[$field] !== '') {
                $query->where($field, $filters[$field]);
            }
        }
        if ($filters['date'] !== '') {
            $today = CarbonImmutable::now(config('operations.timezone'))->startOfDay();
            $start = match ($filters['date']) {
                'today' => $today, 'week' => $today->subDays(6),
                default => CarbonImmutable::createFromFormat('!Y-m-d', $filters['from'], config('operations.timezone')),
            };
            $end = $filters['date'] === 'custom'
                ? CarbonImmutable::createFromFormat('!Y-m-d', $filters['to'], config('operations.timezone'))->addDay() : $today->addDay();
            $query->where('updated_at', '>=', $start->utc())->where('updated_at', '<', $end->utc());
        }
        $grouped = (clone $query)->selectRaw('status, COUNT(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');
        $counts = ['all' => (int) $grouped->sum()];
        foreach (self::STATUSES as $status) {
            $counts[$status] = (int) $grouped->get($status, 0);
        }
        $counts['running'] += (int) $grouped->get('stopping', 0);
        if ($filters['status'] !== '') {
            $query->whereIn('status', $filters['status'] === 'running' ? ['running', 'stopping'] : [$filters['status']]);
        }
        match ($filters['sort']) {
            'oldest' => $query->orderBy('updated_at'),
            'queued' => $query->orderByRaw("CASE WHEN status = 'queued' THEN 0 ELSE 1 END")->orderBy('ready_at'),
            default => $query->orderByDesc('updated_at'),
        };
        $total = $filters['status'] === '' ? $counts['all'] : $counts[$filters['status']];
        $page = min((int) ($input['page'] ?? 1), max(1, (int) ceil($total / 12)));
        $jobs = $query->orderByDesc('id')->with(['project', 'run', 'worker'])->paginate(12, ['*'], 'page', $page)
            ->appends(array_filter($filters, fn (string $value): bool => $value !== ''))
            ->through(fn (MediaTask $task): array => $this->present($task, $request->user()->id));

        return ['jobs' => $jobs, 'counts' => $counts, 'filters' => $filters,
            'refreshedAt' => now()->toIso8601String(), 'timezone' => config('operations.timezone'),
            'pollMilliseconds' => config('operations.poll_milliseconds')];
    }

    public function current(MediaTask $task): bool
    {
        $project = $task->project;
        if (! $project) {
            return false;
        }
        if ($task->media_run_id) {
            return $project->active_run_id === $task->media_run_id && $task->run?->phase === $task->type;
        }

        return (int) MediaTask::where('media_project_id', $project->id)->where('type', $task->type)->max('id') === $task->id;
    }

    public function canRetry(MediaTask $task): bool
    {
        if (! $this->current($task)) {
            return false;
        }
        if ($task->type === 'import') {
            return in_array($task->status, ['failed', 'cancelled'], true) && in_array($task->project->status, ['import_failed', 'import_cancelled'], true);
        }

        return $task->media_run_id && $task->status === 'failed' && $task->run?->status === 'failed' && ! $task->project->busy();
    }

    public function canCancel(MediaTask $task): bool
    {
        if (! $this->current($task)) {
            return false;
        }
        if ($task->type === 'import') {
            return $task->project->status === 'importing' && ! $task->project->import_cancel_requested && in_array($task->status, ['queued', 'running'], true);
        }

        return $task->media_run_id && in_array($task->status, ['queued', 'running', 'retry_waiting'], true)
            && $task->project->busy() && ! $task->run?->cancel_requested;
    }

    public function filePath(MediaTask $task, string $kind): ?string
    {
        if (! $task->project) {
            return null;
        }
        if ($kind === 'poster') {
            return ($task->project->metadata['poster'] ?? false) ? 'media/'.$task->media_project_id.'/poster.jpg' : null;
        }
        if ($task->type !== 'generate' || $task->status !== 'completed' || ! $task->media_run_id) {
            return null;
        }
        $format = $task->run?->settings['output'] ?? 'burn';
        if ($kind === 'audio' && ! in_array($format, ['mp3', 'wav'], true)) {
            return null;
        }
        $relative = 'media/'.$task->media_project_id.'/runs/'.$task->media_run_id.'/'.($kind === 'audio' ? 'narration.'.$format : 'recap.'.($kind === 'video' ? 'mp4' : 'srt'));
        $registered = DB::table('media_files')->where('media_project_id', $task->media_project_id)->where('disk', $task->project->storage_disk)->where('path', $relative)->exists();

        return $registered || ($task->project->storage_disk === 'local' && Storage::disk('local')->exists($relative)) ? $relative : null;
    }

    public function present(MediaTask $task, int $viewerId): array
    {
        $project = $task->project;
        abort_unless($project, 404);
        $elapsed = $task->processing_seconds;
        if ($task->attempt_started_at) {
            $elapsed = ($elapsed ?? 0) + max(0, $task->attempt_started_at->diffInSeconds(now()));
        }
        $waiting = $task->queue_wait_seconds;
        if ($task->status === 'queued' && $task->ready_at) {
            $waiting = ($waiting ?? 0) + max(0, $task->ready_at->diffInSeconds(now()));
        }
        $link = fn (string $name, array $extra = []): string => route('operations.'.$name, ['mediaTask' => $task->id, ...$extra], absolute: false);
        $projectLink = ! ($project->metadata['audio'] ?? false) ? 'media.upload.page' : ($project->active_run_id ? 'media.analysis' : 'media.setup');
        $stage = in_array($task->stage, self::STAGES, true) ? $task->stage : null;
        $states = array_filter($task->stages ?? [], fn ($value, $key): bool => in_array($key, self::STAGES, true) && in_array($value, ['waiting', 'active', 'completed', 'skipped', 'failed'], true), ARRAY_FILTER_USE_BOTH);

        return [
            'id' => $task->id, 'reference' => 'J-'.str_pad((string) $task->id, 5, '0', STR_PAD_LEFT),
            'title' => $project->title, 'type' => $task->type, 'mode' => $task->mode,
            'status' => $task->status, 'stage' => $stage, 'progress' => $task->progress, 'stages' => $states,
            'attempt' => $task->attempts, 'maxAttempts' => $task->max_attempts,
            'errorCode' => $this->safeError($task->error_code), 'isCurrent' => $this->current($task),
            'historyAvailable' => $task->history_available, 'hasPoster' => (bool) ($project->metadata['poster'] ?? false),
            'sourceDuration' => $project->metadata['duration'] ?? null,
            'downloadedBytes' => $task->type === 'import' ? $project->imported_bytes : null,
            'elapsedSeconds' => $elapsed === null ? null : (int) floor($elapsed),
            'queueWaitSeconds' => $waiting === null ? null : (int) floor($waiting),
            'queuedAt' => $task->queued_at?->toIso8601String(), 'startedAt' => $task->started_at?->toIso8601String(),
            'finishedAt' => $task->finished_at?->toIso8601String(), 'updatedAt' => $task->updated_at->toIso8601String(),
            'nextRetryAt' => $task->next_retry_at?->toIso8601String(), 'workerName' => $task->worker?->name,
            'canRetry' => $this->canRetry($task), 'canCancel' => $this->canCancel($task),
            'hasAudio' => (bool) $this->filePath($task, 'audio'),
            'hasVideo' => (bool) $this->filePath($task, 'video'), 'hasSubtitles' => (bool) $this->filePath($task, 'subtitles'),
            'links' => ['details' => $link('job'), 'action' => $link('action'),
                'project' => $project->user_id === $viewerId ? route($projectLink, $project->id, absolute: false) : null,
                'audio' => $link('file', ['kind' => 'audio']), 'poster' => $link('file', ['kind' => 'poster']), 'video' => $link('file', ['kind' => 'video']), 'subtitles' => $link('file', ['kind' => 'subtitles'])],
        ];
    }

    public function detail(MediaTask $task, int $viewerId): array
    {
        $task->load(['project', 'run', 'worker']);
        $events = DB::table('media_task_events')->where('media_task_id', $task->id)->orderByDesc('id')->limit(100)->get()->reverse()->map(fn ($event): array => [
            'status' => $event->status, 'stage' => in_array($event->stage, self::STAGES, true) ? $event->stage : null,
            'attempt' => $event->attempt, 'progress' => $event->progress, 'errorCode' => $this->safeError($event->error_code),
            'at' => CarbonImmutable::parse($event->recorded_at)->toIso8601String(),
        ])->values();

        return [...$this->present($task, $viewerId), 'events' => $events];
    }

    public function safeError(?string $error): ?string
    {
        if ($error === null) {
            return null;
        }
        $safe = ['no_speech', 'no_audio', 'invalid_media', 'invalid_script', 'too_large', 'too_long', 'provider_rate_limit', 'provider_unavailable', 'provider_response_invalid', 'provider_timeout', 'processing_failed', 'media_timeout', 'media_tools_missing', 'ai_key_missing', 'provider_authentication', 'provider_model_unavailable', 'provider_configuration_missing', 'source_not_ready', 'storage_limit', 'storage_unavailable', 'storage_not_configured', 'storage_verification_failed', 'source_missing', 'media_timeout', 'inspection_failed', 'youtube_timeout', 'youtube_unavailable', 'youtube_private', 'youtube_live', 'youtube_restricted', 'youtube_invalid_url', 'render_verification_failed', 'subtitle_font_missing', 'background_unavailable'];

        return in_array($error, $safe, true) ? $error : 'processing_failed';
    }

    public function health(): array
    {
        $workers = MediaWorker::with('currentTask.project')->orderByDesc('heartbeat_at')->limit(30)->get()->map(function (MediaWorker $worker): array {
            $fresh = $worker->heartbeat_at && $worker->heartbeat_at->gte(now()->subSeconds(config('operations.heartbeat_ttl')));
            $state = $worker->stopped_at ? ($worker->error_code ? 'issue' : 'stopped') : (! $worker->heartbeat_at ? 'unknown' : (! $fresh ? 'stale' : ($worker->currentTask ? 'busy' : 'idle')));
            $task = $worker->currentTask;

            return ['id' => $worker->id, 'name' => $worker->name, 'state' => $state,
                'queues' => array_values(array_intersect($worker->queues ?? [], config('operations.queues'))),
                'capacity' => $worker->capacity, 'usedSlots' => in_array($state, ['busy', 'idle'], true) ? ($task ? 1 : 0) : null,
                'heartbeatAt' => $worker->heartbeat_at?->toIso8601String(), 'registeredAt' => $worker->created_at->toIso8601String(),
                'stoppedAt' => $worker->stopped_at?->toIso8601String(),
                'currentJob' => $task && $task->project ? ['id' => $task->id, 'reference' => 'J-'.str_pad((string) $task->id, 5, '0', STR_PAD_LEFT), 'title' => $task->project->title] : null,
            ];
        });

        return ['workers' => $workers->values(), ...$this->queueHealth(),
            'heartbeatTtl' => config('operations.heartbeat_ttl'), 'heartbeatInterval' => config('operations.heartbeat_interval'),
            'refreshedAt' => now()->toIso8601String(), 'timezone' => config('operations.timezone'),
            'pollMilliseconds' => config('operations.poll_milliseconds')];
    }

    public function queueHealth(): array
    {
        $queues = collect(config('operations.queues'))->mapWithKeys(fn (string $queue): array => [$queue => ['name' => $queue, 'ready' => 0, 'delayed' => 0, 'reserved' => 0, 'oldestReadyAt' => null]])->all();
        if (config('queue.default') === 'database') {
            $rows = DB::table(config('queue.connections.database.table', 'jobs'))->whereIn('queue', config('operations.queues'))->get(['queue', 'payload', 'available_at', 'reserved_at']);
            $ids = $rows->map(fn ($row) => json_decode($row->payload, true)['media_task_id'] ?? null)->filter()->all();
            $statuses = MediaTask::whereIn('id', $ids)->pluck('status', 'id');
            foreach ($rows as $row) {
                $id = json_decode($row->payload, true)['media_task_id'] ?? null;
                if ($id && in_array($statuses[$id] ?? null, OperationsTelemetry::TERMINAL, true)) {
                    continue;
                }
                if ($row->reserved_at !== null) {
                    $queues[$row->queue]['reserved']++;
                } elseif ($row->available_at > now()->timestamp) {
                    $queues[$row->queue]['delayed']++;
                } else {
                    $queues[$row->queue]['ready']++;
                    $oldest = $queues[$row->queue]['oldestReadyAt'];
                    $at = CarbonImmutable::createFromTimestampUTC($row->available_at)->toIso8601String();
                    $queues[$row->queue]['oldestReadyAt'] = $oldest === null || $at < $oldest ? $at : $oldest;
                }
            }
        }

        return ['queues' => array_values($queues), 'queueReported' => config('queue.default') === 'database'];
    }
}
