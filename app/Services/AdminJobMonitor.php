<?php

namespace App\Services;

use App\Exceptions\MediaFailure;
use App\Jobs\InspectMediaProject;
use App\Jobs\PrepareMediaPreview;
use App\Models\MediaPreview;
use App\Models\MediaTask;
use App\Models\MediaTaskAction;
use App\Models\UsageRecord;
use App\Models\User;
use App\Services\Media\AutomaticMediaWorker;
use App\Services\Media\MediaRunActions;
use App\Services\Media\MediaRuntime;
use App\Services\Media\OperationsMonitor;
use App\Services\Media\OperationsTelemetry;
use App\Services\Media\ProviderSettings;
use App\Services\Media\RetentionCleanup;
use App\Services\Media\StorageSettings;
use App\Services\Media\YouTubeImports;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminJobMonitor
{
    public function __construct(private OperationsMonitor $monitor) {}

    public function filters(Request $request): array
    {
        $input = $request->validate([
            'q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::in(OperationsMonitor::STATUSES)],
            'type' => ['nullable', Rule::in(['import', 'validation', 'analysis', 'generate', 'preview'])],
            'date' => ['nullable', Rule::in(['all', 'today', 'week', 'month', 'custom'])],
            'from' => ['nullable', 'required_if:date,custom', 'date_format:Y-m-d'],
            'to' => ['nullable', 'required_if:date,custom', 'date_format:Y-m-d', 'after_or_equal:from'],
            'sort' => ['nullable', Rule::in(['newest', 'oldest', 'longest'])],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000000'], 'per_page' => ['nullable', Rule::in([10, 20, 50])],
        ]);

        return array_replace(['q' => '', 'status' => '', 'type' => '', 'date' => 'month', 'from' => '', 'to' => '',
            'sort' => 'newest', 'per_page' => 10, 'page' => 1], array_filter($input, fn ($value) => $value !== null));
    }

    public function browse(Request $request): array
    {
        $filters = $this->filters($request);
        $query = MediaTask::whereHas('project.user');
        if (filled($filters['q'])) {
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($filters['q'])).'%';
            $query->where(function (Builder $q) use ($term, $filters): void {
                $q->whereHas('project', fn (Builder $project) => $project->whereRaw("title LIKE ? ESCAPE '!'", [$term])
                    ->orWhereHas('user', fn (Builder $user) => $user->whereRaw("(name LIKE ? ESCAPE '!' OR email LIKE ? ESCAPE '!')", [$term, $term])))
                    ->orWhere('media_run_id', trim($filters['q']));
                if (preg_match('/^(?:(?:JOB|J)-)?0*([0-9]{1,12})$/i', trim($filters['q']), $match)) {
                    $q->orWhere('id', (int) $match[1]);
                }
            });
        }
        if ($filters['type'] !== '') {
            $query->where('type', $filters['type']);
        }
        if ($filters['date'] !== 'all') {
            $today = CarbonImmutable::now(config('operations.timezone'))->startOfDay();
            $start = match ($filters['date']) {
                'today' => $today, 'week' => $today->subDays(6), 'month' => $today->subDays(29),
                default => CarbonImmutable::parse($filters['from'], config('operations.timezone'))->startOfDay(),
            };
            $end = $filters['date'] === 'custom' ? CarbonImmutable::parse($filters['to'], config('operations.timezone'))->startOfDay()->addDay() : $today->addDay();
            $query->where('updated_at', '>=', $start->utc())->where('updated_at', '<', $end->utc());
        }
        $grouped = (clone $query)->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');
        $counts = ['all' => (int) $grouped->sum()];
        foreach (OperationsMonitor::STATUSES as $status) {
            $counts[$status] = (int) $grouped->get($status, 0);
        }
        $counts['running'] += (int) $grouped->get('stopping', 0);
        if ($filters['status'] !== '') {
            $query->whereIn('status', $filters['status'] === 'running' ? ['running', 'stopping'] : [$filters['status']]);
        }
        if ($filters['sort'] === 'longest') {
            $elapsed = DB::getDriverName() === 'sqlite'
                ? "COALESCE(processing_seconds,0) + CASE WHEN attempt_started_at IS NULL THEN 0 ELSE MAX(0,strftime('%s',?) - strftime('%s',attempt_started_at)) END"
                : 'COALESCE(processing_seconds,0) + CASE WHEN attempt_started_at IS NULL THEN 0 ELSE GREATEST(0,TIMESTAMPDIFF(SECOND,attempt_started_at,?)) END';
            $query->orderByRaw($elapsed.' DESC', [now()->toDateTimeString()]);
        } else {
            $query->orderBy('updated_at', $filters['sort'] === 'oldest' ? 'asc' : 'desc');
        }
        $page = $query->orderByDesc('id')->with(['project.user.currentPackageAssignment', 'run', 'worker'])
            ->paginate((int) $filters['per_page'], ['*'], 'page', min((int) $filters['page'], max(1, (int) ceil(($counts[$filters['status'] ?: 'all'] ?? 0) / $filters['per_page']))));

        return ['items' => $page->getCollection()->map(fn (MediaTask $task) => $this->present($task))->all(),
            'counts' => $counts, 'filters' => $filters,
            'pagination' => ['page' => $page->currentPage(), 'pages' => $page->lastPage(), 'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem()],
            'timezone' => config('operations.timezone'), 'today' => now(config('operations.timezone'))->toDateString(),
            'refreshed_at' => now()->toIso8601String(), 'poll_milliseconds' => max(3000, (int) config('operations.poll_milliseconds')),
            'worker' => app(AutomaticMediaWorker::class)->status(),
            'endpoints' => ['index' => route('admin.jobs.index', absolute: false), 'workers' => route('admin.jobs.workers', absolute: false)]];
    }

    public function permissions(MediaTask $task): array
    {
        $project = $task->project;
        $current = $this->monitor->current($task);
        $retry = $this->monitor->canRetry($task);
        $cancel = $this->monitor->canCancel($task);
        if ($current && $task->type === 'validation') {
            $retry = $task->status === 'failed' && $project->status === 'invalid' && ! $project->busy();
        }
        if ($current && $task->type === 'preview' && ($preview = $this->preview($task))) {
            $retry = $task->status === 'failed' && $preview->status === 'failed' && ! $project->busy();
            $cancel = in_array($preview->status, ['queued', 'running'], true) && ! $preview->cancel_requested;
        }
        $blocked = ! $current ? 'historical' : null;
        if ($retry) {
            $user = $project->user;
            $assignment = $user->currentPackageAssignment;
            if ($user->account_status !== 'active' || (($user->managed_account || $assignment) && (! $assignment || $assignment->status() !== 'active'))) {
                $retry = false;
                $blocked = 'account_unavailable';
            } elseif ($task->type !== 'import' && ($project->source_expired_at || ($project->source_expires_at?->isPast() && app(StorageSettings::class)->policy()['retention_enabled']))) {
                $retry = false;
                $blocked = 'source_missing';
            }
        }

        return ['canRetry' => (bool) $retry, 'canCancel' => (bool) $cancel, 'retryBlocked' => $blocked];
    }

    public function present(MediaTask $task): array
    {
        $task->loadMissing(['project.user.currentPackageAssignment', 'run', 'worker']);
        $job = $this->monitor->present($task, 0);
        $link = fn (string $kind): string => route('admin.jobs.file', ['mediaTask' => $task->id, 'kind' => $kind], absolute: false);

        return [...$job, ...$this->permissions($task), 'reference' => 'JOB-'.str_pad((string) $task->id, 7, '0', STR_PAD_LEFT),
            'hasVideo' => (bool) $this->filePath($task, 'video'), 'hasAudio' => (bool) $this->filePath($task, 'audio'), 'hasSubtitles' => (bool) $this->filePath($task, 'subtitles'),
            'audioFormat' => $task->type === 'preview' ? 'WAV' : strtoupper($task->run?->settings['output'] ?? 'wav'),
            'resultsExpired' => (bool) ($task->project->artifacts_expires_at?->isPast() && app(StorageSettings::class)->policy()['retention_enabled']),
            'customer' => ['id' => $task->project->user_id, 'name' => $task->project->user->name, 'email' => $task->project->user->email],
            'links' => ['details' => route('admin.jobs.show', $task->id, absolute: false),
                'action' => route('admin.jobs.action', $task->id, absolute: false), 'project' => null,
                'poster' => $link('poster'), 'video' => $link('video'), 'audio' => $link('audio'), 'subtitles' => $link('subtitles')]];
    }

    public function detail(MediaTask $task): array
    {
        $job = $this->present($task);
        $history = $this->monitor->detail($task, 0)['events'];
        $actions = MediaTaskAction::where('media_task_id', $task->id)->with('actor:id,name')->oldest('id')->get()
            ->map(fn (MediaTaskAction $action) => ['id' => $action->id, 'action' => $action->action, 'actor' => $action->actor?->name, 'reason' => $action->reason, 'at' => $action->created_at->toIso8601String()]);
        $user = $task->project->user;
        $assignment = $user->currentPackageAssignment;
        $record = $task->media_run_id ? UsageRecord::where('media_run_id', $task->media_run_id)->first() : null;
        $snapshot = $record?->package_snapshot ?: $assignment?->effectiveSnapshot();

        return [...$job, 'events' => $history, 'admin_actions' => $actions->all(),
            'source' => $task->project->source_type === 'youtube' ? 'youtube' : 'local',
            'settings' => array_intersect_key($task->run?->settings ?? $task->project->settings ?? [], array_flip(['mode', 'output', 'aspect', 'review_before_render', 'keep_background', 'voice', 'burn_subtitles'])),
            'usage' => ['package' => $snapshot ? ['name' => $snapshot['name'], 'version' => $snapshot['version']] : null,
                'reserved_seconds' => $record?->reserved_seconds ?? 0, 'charged_seconds' => ($record?->charged_seconds ?? 0) - ($record?->returned_seconds ?? 0),
                'videos' => ($record?->charged_videos ?? 0) - ($record?->returned_videos ?? 0),
                'reserved_videos' => $record?->reserved_videos ?? 0, 'status' => $record?->status,
                'period_start' => $record?->period_start?->toIso8601String(), 'period_end' => $record?->period_end?->toIso8601String(),
                'ledger_url' => route('admin.usage.index', ['q' => $user->email], absolute: false)],
            'customer_url' => route('admin.users.index', ['q' => $user->email], absolute: false)];
    }

    public function filePath(MediaTask $task, string $kind): ?string
    {
        $project = $task->project;
        if ($kind === 'poster') {
            return $this->monitor->filePath($task, $kind);
        }
        if ($task->status !== 'completed') {
            return null;
        }
        $path = null;
        if ($task->type === 'preview' && ($preview = $this->preview($task)) && $preview->status === 'completed') {
            $path = ($kind === 'video' && $preview->kind === 'video') || ($kind === 'audio' && $preview->kind === 'voice') ? $preview->path : null;
        } elseif ($task->run?->status === 'completed' && in_array($task->type, ['analysis', 'generate'], true)) {
            $output = $task->run->settings['output'] ?? 'burn';
            if ($kind === 'subtitles' || ($kind === 'video' && $output === 'burn') || ($kind === 'audio' && in_array($output, ['mp3', 'wav'], true))) {
                $path = 'media/'.$project->id.'/runs/'.$task->media_run_id.'/'.($kind === 'audio' ? 'narration.'.$output : 'recap.'.($kind === 'video' ? 'mp4' : 'srt'));
            }
        }
        if (! $path || ! str_starts_with($path, 'media/'.$project->id.'/') || str_contains($path, '..') || str_contains($path, '\\')) {
            return null;
        }
        if ($project->artifacts_expires_at?->isPast() && app(StorageSettings::class)->policy()['retention_enabled']
            && ! app(RetentionCleanup::class)->protected($project)) {
            return null;
        }
        $registered = DB::table('media_files')->where('media_project_id', $project->id)->where('path', $path)->first();
        if ($registered && ($registered->state !== 'active' || ($registered->expires_at && CarbonImmutable::parse($registered->expires_at)->isPast()
            && app(StorageSettings::class)->policy()['retention_enabled'] && ! app(RetentionCleanup::class)->protected($project)))) {
            return null;
        }

        return $registered || Storage::disk('local')->exists($path) ? $path : null;
    }

    private function preview(MediaTask $task): ?MediaPreview
    {
        return str_starts_with($task->source_key, 'preview:')
            ? MediaPreview::whereKey(substr($task->source_key, 8))->where('media_project_id', $task->media_project_id)->first() : null;
    }

    private function retryChecks(MediaTask $task): void
    {
        $project = $task->project;
        $user = $project->user;
        $assignment = $user->currentPackageAssignment;
        if ($user->account_status !== 'active' || (($user->managed_account || $assignment) && (! $assignment || $assignment->status() !== 'active'))) {
            throw new MediaFailure('account_unavailable');
        }
        if (! app(MediaRuntime::class)->available()) {
            throw new MediaFailure('media_tools_missing');
        }
        if ($task->type === 'import') {
            app(UsageLedger::class)->checkUpload($user->id, $project->expected_bytes, 'youtube');

            return;
        }
        $source = DB::table('media_files')->where('media_project_id', $project->id)->where('path', $project->source_path)->first();
        if ($project->source_expired_at || ($source && $source->state !== 'active')) {
            throw new MediaFailure('source_missing');
        }
        if (! Storage::disk('local')->exists($project->source_path)) {
            try {
                if (! app(StorageSettings::class)->filesystem($project->storage_profile_id, $project->storage_disk ?? 'r2')->exists($project->source_path)) {
                    throw new MediaFailure('source_missing');
                }
            } catch (MediaFailure $failure) {
                throw $failure;
            } catch (\Throwable) {
                throw new MediaFailure('storage_unavailable');
            }
        }
        if ($task->type === 'preview' && ($preview = $this->preview($task))) {
            if (! app(ProviderSettings::class)->withRevision($preview->provider_revision_id, fn () => app(ProviderSettings::class)->ready())) {
                throw new MediaFailure('ai_key_missing');
            }
            $feature = match ($preview->kind) {
                'voice' => 'voice_preview', 'video' => 'video_preview', default => 'script_edit',
            };
            if ($assignment && ! ($assignment->effectiveSnapshot()['definition']['features'][$feature] ?? false)) {
                throw ValidationException::withMessages(['action' => 'The current package does not include this preview feature.']);
            }
        }
    }

    public function act(User $actor, MediaTask $task, array $input): array
    {
        abort_unless($actor->is_admin, 403);
        $reason = trim($input['reason']);
        $fingerprint = hash_hmac('sha256', json_encode([$actor->id, $task->id, $input['action'], $reason], JSON_THROW_ON_ERROR), (string) config('app.key'));

        return Cache::lock('admin-job-request:'.$input['request_id'], 45)->block(5, fn () => Cache::lock('admin-job-task:'.$task->id, 30)->block(5, fn () => DB::transaction(function () use ($actor, $task, $input, $reason, $fingerprint): array {
            $previous = MediaTaskAction::where('request_id', $input['request_id'])->first();
            if ($previous) {
                abort_unless($previous->actor_id === $actor->id && hash_equals($previous->fingerprint, $fingerprint), 409, 'This request was already used.');

                return [...$previous->result, 'replayed' => true];
            }
            $task = MediaTask::with(['project.user.currentPackageAssignment', 'run'])->findOrFail($task->id);
            $retry = $input['action'] === 'retry';
            $permissions = $this->permissions($task);
            abort_unless($permissions[$retry ? 'canRetry' : 'canCancel'], 409, 'This job changed or the action is no longer available. Refresh and try again.');
            if ($retry) {
                $this->retryChecks($task);
            }
            if ($task->type === 'preview') {
                $preview = $this->preview($task);
                abort_unless($preview, 409);
                if ($retry) {
                    $preview->update(['status' => 'queued', 'cancel_requested' => false, 'error_code' => null, 'progress' => 0]);
                    PrepareMediaPreview::dispatch($preview->id)->afterCommit();
                } else {
                    $preview->update(['cancel_requested' => true, 'status' => $preview->status === 'queued' ? 'cancelled' : 'running']);
                }
            } elseif ($task->type === 'validation') {
                abort_unless($retry, 409);
                app(OperationsTelemetry::class)->save($task, ['status' => 'queued', 'error_code' => null, 'finished_at' => null, 'progress' => 0]);
                $task->project->update(['status' => 'validating', 'error_code' => null]);
                InspectMediaProject::dispatch($task->media_project_id)->afterCommit();
            } elseif ($task->type === 'import') {
                if ($retry) {
                    app(OperationsTelemetry::class)->save($task, ['status' => 'queued', 'error_code' => null, 'finished_at' => null, 'progress' => 0]);
                    app(YouTubeImports::class)->retry($task->project, $task->id);
                } else {
                    app(YouTubeImports::class)->cancel($task->project, $task->id);
                }
            } else {
                $actions = app(MediaRunActions::class);
                $retry ? $actions->retry($task->project, $task->media_run_id, $task->type)
                    : $actions->cancel($task->project, $task->media_run_id, $task->type);
            }
            $result = ['job' => $this->present($task->fresh())];
            MediaTaskAction::create(['media_task_id' => $task->id, 'actor_id' => $actor->id,
                'request_id' => $input['request_id'], 'action' => $input['action'], 'reason' => $reason,
                'fingerprint' => $fingerprint, 'result' => $result]);

            return $result;
        }, 5)
        )
        );
    }
}
