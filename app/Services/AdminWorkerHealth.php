<?php

namespace App\Services;

use App\Models\MediaTask;
use App\Models\MediaWorker;
use App\Services\Media\AutomaticMediaWorker;
use App\Services\Media\OperationsMonitor;
use App\Services\Media\OperationsTelemetry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Throwable;

class AdminWorkerHealth
{
    public const STATES = ['busy', 'idle', 'stale', 'unknown', 'stopped', 'issue'];

    public function __construct(private OperationsMonitor $monitor) {}

    private function stateSql(): string
    {
        return "CASE WHEN stopped_at IS NOT NULL THEN CASE WHEN error_code IS NULL THEN 'stopped' ELSE 'issue' END "
            ."WHEN heartbeat_at IS NULL THEN 'unknown' WHEN heartbeat_at < ? THEN 'stale' "
            .'WHEN EXISTS (SELECT 1 FROM media_tasks WHERE media_tasks.id = media_workers.current_task_id '
            ."AND media_tasks.status IN ('queued','running','retry_waiting','stopping')) THEN 'busy' ELSE 'idle' END";
    }

    private function cutoff(): string
    {
        return now()->subSeconds((int) config('operations.heartbeat_ttl'))->toDateTimeString();
    }

    private function state(MediaWorker $worker): string
    {
        if ($worker->stopped_at) {
            return $worker->error_code ? 'issue' : 'stopped';
        }
        if (! $worker->heartbeat_at) {
            return 'unknown';
        }
        if ($worker->heartbeat_at->lt($this->cutoff())) {
            return 'stale';
        }

        return $worker->currentTask && ! in_array($worker->currentTask->status, OperationsTelemetry::TERMINAL, true) ? 'busy' : 'idle';
    }

    public function browse(Request $request): array
    {
        $input = $request->validate([
            'q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::in(self::STATES)],
            'type' => ['nullable', Rule::in(['processing', 'preparation', 'mixed', 'unknown'])],
            'scope' => ['nullable', Rule::in(['all', 'current', 'history'])],
            'history' => ['nullable', 'boolean'], 'page' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'per_page' => ['nullable', Rule::in([10, 20, 50])],
        ]);
        $filters = array_replace(['q' => '', 'status' => '', 'type' => '', 'history' => false, 'scope' => 'all', 'page' => 1, 'per_page' => 10],
            array_filter($input, fn ($value) => $value !== null));
        $filters['scope'] = $input['scope'] ?? ($request->has('history') ? ($request->boolean('history') ? 'history' : 'current') : 'all');
        $filters['history'] = $filters['scope'] === 'history';
        $filters['q'] = trim($filters['q']);
        $filters['per_page'] = (int) $filters['per_page'];
        $stateSql = $this->stateSql();
        $aggregate = DB::query()->fromSub(MediaWorker::whereNull('stopped_at')->selectRaw($stateSql.' AS state', [$this->cutoff()]), 'instances')
            ->selectRaw('state, COUNT(*) AS total')->groupBy('state')->pluck('total', 'state');
        $counts = ['online' => (int) $aggregate->get('busy', 0) + (int) $aggregate->get('idle', 0),
            'busy' => (int) $aggregate->get('busy', 0), 'idle' => (int) $aggregate->get('idle', 0),
            'attention' => (int) $aggregate->get('stale', 0) + (int) $aggregate->get('unknown', 0)];
        $historyTotal = MediaWorker::whereNotNull('stopped_at')->count();
        $query = MediaWorker::query();
        if ($filters['scope'] === 'history') {
            $query->whereNotNull('stopped_at');
        } elseif ($filters['scope'] === 'current') {
            $query->whereNull('stopped_at');
        }
        if ($filters['status'] !== '') {
            $query->whereRaw('('.$stateSql.') = ?', [$this->cutoff(), $filters['status']]);
        }
        if ($filters['q'] !== '') {
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['q']).'%';
            $query->where(fn (Builder $query) => $query->whereRaw("(name LIKE ? ESCAPE '!' OR id LIKE ? ESCAPE '!')", [$term, $term]));
        }
        if ($filters['type'] !== '') {
            $processing = in_array($filters['type'], ['processing', 'mixed'], true);
            $preparation = in_array($filters['type'], ['preparation', 'mixed'], true);
            $processing ? $query->whereJsonContains('queues', 'media') : $query->whereJsonDoesntContain('queues', 'media');
            $preparation ? $query->whereJsonContains('queues', 'media-inspect') : $query->whereJsonDoesntContain('queues', 'media-inspect');
        }
        $total = (clone $query)->count();
        $page = min((int) $filters['page'], max(1, (int) ceil($total / $filters['per_page'])));
        $workers = $query->with('currentTask.project')->orderByRaw('CASE ('.$stateSql.") WHEN 'busy' THEN 0 WHEN 'idle' THEN 1 WHEN 'stale' THEN 2 WHEN 'unknown' THEN 3 ELSE 4 END", [$this->cutoff()])->orderByDesc('heartbeat_at')->orderByDesc('created_at')->orderBy('id')
            ->paginate($filters['per_page'], ['*'], 'page', $page);
        try {
            $queue = $this->monitor->queueHealth();
        } catch (Throwable) {
            $queue = ['queues' => [], 'queueReported' => false];
        }
        $automatic = app(AutomaticMediaWorker::class)->status();
        $automatic['errorCode'] = in_array($automatic['errorCode'], ['auto_worker_unavailable', 'auto_worker_launch_failed'], true)
            ? $automatic['errorCode'] : ($automatic['errorCode'] ? 'worker_unavailable' : null);
        $ready = array_sum(array_column($queue['queues'], 'ready'));
        $oldest = collect($queue['queues'])->pluck('oldestReadyAt')->filter()->sort()->first();
        $overdue = $oldest && now()->diffInSeconds($oldest, true) > (int) config('operations.heartbeat_ttl') + 15;
        $processingState = match (true) {
            $counts['busy'] > 0 => 'processing', $counts['online'] > 0 => 'idle',
            $automatic['state'] === 'starting' => 'starting',
            $counts['attention'] > 0 => 'attention',
            ! $automatic['enabled'] => 'manual',
            in_array($automatic['state'], ['failed', 'unsupported'], true) => 'attention',
            $ready > 0 && $overdue => 'attention', $ready > 0 => 'waiting', default => 'standby',
        };

        return ['items' => $workers->getCollection()->map(fn (MediaWorker $worker) => $this->present($worker))->all(),
            'counts' => $counts, 'historyTotal' => $historyTotal, 'filters' => $filters,
            'pagination' => ['page' => $workers->currentPage(), 'pages' => $workers->lastPage(), 'total' => $workers->total(),
                'from' => $workers->firstItem(), 'to' => $workers->lastItem()],
            ...$queue, 'automatic' => $automatic, 'processingState' => $processingState,
            'heartbeatTtl' => (int) config('operations.heartbeat_ttl'), 'heartbeatInterval' => (int) config('operations.heartbeat_interval'),
            'refreshedAt' => now()->toIso8601String(), 'timezone' => config('operations.timezone'),
            'pollMilliseconds' => max(3000, (int) config('operations.poll_milliseconds')),
            'endpoints' => ['index' => route('admin.jobs.workers', absolute: false), 'jobs' => route('admin.jobs.index', absolute: false),
                'settings' => route('admin.settings.providers', absolute: false), 'login' => route('admin.login', absolute: false)]];
    }

    private function job(?MediaTask $task): ?array
    {
        if (! $task?->project) {
            return null;
        }

        return ['id' => $task->id, 'reference' => 'JOB-'.str_pad((string) $task->id, 7, '0', STR_PAD_LEFT),
            'title' => $task->project->title, 'type' => $task->type, 'status' => $task->status,
            'progress' => $task->progress === null ? null : min(100, max(0, $task->progress)),
            'stage' => in_array($task->stage, OperationsMonitor::STAGES, true) ? $task->stage : null,
            'startedAt' => $task->started_at?->toIso8601String(), 'finishedAt' => $task->finished_at?->toIso8601String(),
            'url' => route('admin.jobs.index', ['q' => 'JOB-'.str_pad((string) $task->id, 7, '0', STR_PAD_LEFT)], absolute: false)];
    }

    public function present(MediaWorker $worker): array
    {
        $worker->loadMissing('currentTask.project');
        $state = $this->state($worker);
        $queues = array_values(array_intersect($worker->queues ?? [], config('operations.queues')));
        $type = count($queues) === 2 ? 'mixed' : (in_array('media', $queues, true) ? 'processing' : (in_array('media-inspect', $queues, true) ? 'preparation' : 'unknown'));
        $job = $this->job($worker->currentTask);
        $end = $worker->stopped_at ?? (in_array($state, ['busy', 'idle'], true) ? now() : $worker->heartbeat_at);

        return ['id' => $worker->id, 'reference' => 'wrk-'.substr($worker->id, -8), 'name' => $worker->name,
            'type' => $type, 'state' => $state, 'automatic' => $worker->automatic, 'queues' => $queues,
            'capacity' => $worker->capacity, 'usedSlots' => in_array($state, ['busy', 'idle'], true) ? ($state === 'busy' ? 1 : 0) : null,
            'heartbeatAt' => $worker->heartbeat_at?->toIso8601String(),
            'heartbeatSeconds' => $worker->heartbeat_at ? max(0, (int) $worker->heartbeat_at->diffInSeconds(now())) : null,
            'registeredAt' => $worker->created_at->toIso8601String(), 'stoppedAt' => $worker->stopped_at?->toIso8601String(),
            'uptimeSeconds' => $end ? max(0, (int) $worker->created_at->diffInSeconds($end)) : null,
            'uptimeCurrent' => in_array($state, ['busy', 'idle'], true),
            'job' => $job, 'jobCurrent' => $state === 'busy' && $job !== null,
            'errorCode' => $worker->error_code ? 'worker_unavailable' : null,
            'links' => ['details' => route('admin.jobs.worker', $worker->id, absolute: false), 'job' => $job['url'] ?? null]];
    }

    public function detail(MediaWorker $worker): array
    {
        $data = $this->present($worker);
        $jobs = MediaTask::where('media_worker_id', $worker->id)->whereHas('project')->with('project')->latest('updated_at')->limit(8)->get();
        if (! $data['job'] && ($latest = $jobs->first())) {
            $data['job'] = $this->job($latest);
            $data['links']['job'] = $data['job']['url'];
        }
        $events = [['type' => 'registered', 'at' => $data['registeredAt']]];
        if ($data['heartbeatAt']) {
            $events[] = ['type' => 'heartbeat', 'at' => $data['heartbeatAt']];
        }
        if ($data['stoppedAt']) {
            $events[] = ['type' => 'stopped', 'at' => $data['stoppedAt']];
        }
        usort($events, fn ($a, $b) => strcmp($a['at'], $b['at']));

        return [...$data, 'recentJobs' => $jobs->map(fn (MediaTask $task) => $this->job($task))->all(),
            'events' => $events, 'refreshedAt' => now()->toIso8601String(),
            'heartbeatTtl' => (int) config('operations.heartbeat_ttl'), 'heartbeatInterval' => (int) config('operations.heartbeat_interval')];
    }
}
