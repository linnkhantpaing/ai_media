<?php

namespace App\Services;

use App\Models\AdminNotice;
use App\Models\CustomerPackageAssignment;
use App\Models\MediaTask;
use App\Models\User;
use App\Services\Media\ProviderSettings;
use App\Services\Media\StorageSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminDashboard
{
    public function __construct(private RevenueReport $revenue, private ProcessingReport $processing) {}

    private function financial(array $bounds, string $currency): Builder
    {
        return $this->revenue->events()->where('e.currency', $currency)->where('e.occurred_at', '>=', $bounds['from'])
            ->where('e.occurred_at', '<', $bounds['until'])->where('e.occurred_at', '<=', now());
    }

    private function usage(array $bounds): Builder
    {
        return $this->processing->events()->where('e.occurred_at', '>=', $bounds['from'])
            ->where('e.occurred_at', '<', $bounds['until'])->where('e.occurred_at', '<=', now());
    }

    private function money(Builder $query): array
    {
        $row = $query->selectRaw("COALESCE(SUM(CASE WHEN e.type = 'payment' THEN e.amount_minor ELSE 0 END),0) AS gross, COALESCE(SUM(CASE WHEN e.type = 'refund' THEN e.amount_minor ELSE 0 END),0) AS refunds")->first();

        return ['gross' => (int) $row->gross, 'refunds' => (int) $row->refunds, 'net' => (int) $row->gross - (int) $row->refunds];
    }

    private function secondsSql(): string
    {
        return "CASE WHEN e.metric = 'charged' THEN e.seconds WHEN e.metric = 'adjusted' THEN -e.seconds ELSE 0 END";
    }

    private function exports(Builder $query): array
    {
        $row = $query->selectRaw('COALESCE(SUM('.$this->secondsSql().'),0) AS seconds')
            ->selectRaw("SUM(CASE WHEN e.metric = 'task' AND e.stage = 'generate' AND e.outcome = 'completed' THEN 1 ELSE 0 END) AS successful, SUM(CASE WHEN e.metric = 'task' AND e.stage = 'generate' AND e.outcome = 'failed' THEN 1 ELSE 0 END) AS failed")->first();

        return ['seconds' => (int) $row->seconds, 'successful' => (int) $row->successful, 'failed' => (int) $row->failed];
    }

    private function trend(Builder $query, array $bounds): array
    {
        $days = (int) $bounds['start']->diffInDays($bounds['end']);
        $group = $days <= 90 ? 'day' : 'month';
        $months = max(1, (int) ceil($bounds['start']->startOfMonth()->diffInMonths($bounds['end'])));
        $stride = $group === 'day' ? 1 : max(1, (int) ceil($months / 36));
        $cursor = $bounds['start'];
        $buckets = [];
        $cases = [];
        $bindings = [];
        while ($cursor < $bounds['end']) {
            $next = $group === 'day' ? $cursor->addDay() : $cursor->startOfMonth()->addMonths($stride)->min($bounds['end']);
            $key = $cursor->format('Y-m-d');
            $buckets[] = ['date' => $key, 'gross' => 0, 'refunds' => 0];
            $cases[] = 'WHEN e.occurred_at >= ? AND e.occurred_at < ? THEN ?';
            array_push($bindings, $cursor->utc()->toDateTimeString(), $next->utc()->toDateTimeString(), $key);
            $cursor = $next;
        }
        /** Local calendar boundaries preserve historical DST and half-hour offsets without requiring database timezone tables. */
        $rows = $query->selectRaw('CASE '.implode(' ', $cases).' END AS bucket', $bindings)
            ->selectRaw("SUM(CASE WHEN e.type = 'payment' THEN e.amount_minor ELSE 0 END) AS gross, SUM(CASE WHEN e.type = 'refund' THEN e.amount_minor ELSE 0 END) AS refunds")
            ->groupBy('bucket')->get()->keyBy('bucket');
        foreach ($buckets as &$bucket) {
            $row = $rows->get($bucket['date']);
            $bucket['gross'] = (int) ($row->gross ?? 0);
            $bucket['refunds'] = (int) ($row->refunds ?? 0);
        }

        return ['group' => $group, 'points' => $buckets];
    }

    public function overview(array $filters): array
    {
        $bounds = $this->processing->bounds($filters);
        if ($filters['dates'] === 'all') {
            $earliest = collect([$this->revenue->events()->where('e.occurred_at', '<=', now())->min('e.occurred_at'),
                $this->processing->events()->where('e.occurred_at', '<=', now())->min('e.occurred_at')])->filter()->sort()->first();
            if ($earliest) {
                $bounds['start'] = CarbonImmutable::parse($earliest, 'UTC')->setTimezone($bounds['timezone'])->startOfDay();
                $bounds['from'] = $bounds['start']->utc();
            }
        }
        $finance = $this->financial($bounds, $filters['currency']);
        $usage = $this->usage($bounds);
        $summary = [...$this->money(clone $finance), ...$this->exports(clone $usage)];
        $previous = null;
        if ($filters['dates'] !== 'all') {
            $days = (int) $bounds['start']->diffInDays($bounds['end']);
            $prior = [...$bounds, 'from' => $bounds['start']->subDays($days)->utc(), 'until' => $bounds['from']];
            $previous = [...$this->money($this->financial($prior, $filters['currency'])), ...$this->exports($this->usage($prior))];
        }
        $active = CustomerPackageAssignment::query()->current()->whereNull('closed_kind')->where('starts_at', '<=', now())->where('ends_at', '>', now())
            ->whereIn('user_id', User::where('is_admin', false)->where('account_status', 'active')->select('id'))->distinct()->count('user_id');
        $modes = (clone $usage)->whereIn('e.metric', ['charged', 'adjusted'])->select('e.mode')->selectRaw('SUM('.$this->secondsSql().') AS seconds')->groupBy('e.mode')->get()
            ->map(fn ($row) => ['key' => in_array($row->mode, ['dub', 'subs'], true) ? $row->mode : 'unknown', 'seconds' => (int) $row->seconds])
            ->groupBy('key')->map(fn ($rows, $key) => ['key' => $key, 'seconds' => $rows->sum('seconds')])->filter(fn ($mode) => $mode['seconds'] !== 0)->values()->all();
        $packages = (clone $usage)->leftJoin('packages as pkg', 'pkg.id', '=', 'e.package_id')->select('e.package_id', 'pkg.name')
            ->selectRaw('SUM('.$this->secondsSql().') AS seconds')->groupBy('e.package_id', 'pkg.name')->havingRaw('SUM('.$this->secondsSql().') > 0')
            ->orderByDesc('seconds')->orderBy('e.package_id')->get()->map(fn ($row) => ['id' => $row->package_id, 'name' => $row->name, 'seconds' => (int) $row->seconds])->all();
        $packageTotal = array_sum(array_column($packages, 'seconds'));
        if (count($packages) > 5) {
            $other = array_slice($packages, 4);
            $packages = [...array_slice($packages, 0, 4), ['id' => null, 'name' => null, 'seconds' => array_sum(array_column($other, 'seconds')), 'others' => true]];
        }
        $recentIds = (clone $usage)->where('e.metric', 'task')->where('e.stage', 'generate')->orderByDesc('e.occurred_at')->orderByDesc('e.task_id')->limit(5)->pluck('e.task_id');
        $tasks = MediaTask::with(['project.user'])->whereIn('id', $recentIds)->get()->keyBy('id');
        $recent = $recentIds->map(function ($id) use ($tasks): ?array {
            $task = $tasks->get($id);
            if (! $task?->project?->user) {
                return null;
            }

            return ['id' => $task->id, 'title' => $task->project->title, 'customer' => $task->project->user->name, 'mode' => $task->mode,
                'status' => $task->status, 'duration' => $task->started_at && $task->finished_at && $task->started_at <= $task->finished_at && $task->processing_seconds !== null && $task->processing_seconds >= 0 ? $task->processing_seconds : null,
                'poster' => data_get($task->project->metadata, 'poster') ? route('admin.jobs.file', ['mediaTask' => $task->id, 'kind' => 'poster'], absolute: false) : null,
                'url' => route('admin.jobs.index', ['q' => 'JOB-'.$task->id, 'date' => 'all'], false)];
        })->filter()->values()->all();
        $workers = app(AdminWorkerHealth::class)->browse(Request::create('/'));
        $provider = app(ProviderSettings::class);
        $configuration = $provider->current();
        $check = DB::table('media_provider_checks')->where('fingerprint', $provider->fingerprint($configuration))->where('capability', 'text')->latest('id')->first();
        $providerState = ! $provider->ready($configuration) ? 'unconfigured' : (! $check ? 'untested' : ($check->status === 'success' ? 'tested' : 'failed'));
        $storage = (int) DB::table('media_files')->whereNotNull('verified_at')->where('state', '!=', 'deleted')->sum('bytes');
        $budget = (int) app(StorageSettings::class)->policy()['budget'];
        $scheduler = app(ScheduledTasks::class)->scheduler();
        $failing = app(ScheduledTasks::class)->failing();
        $attention = [
            ['key' => 'failedJobs', 'count' => AdminNotice::where('category', 'jobs')->where('target_type', 'job')->where('issue', 'attention')->count(), 'url' => route('admin.notifications.index', ['category' => 'jobs', 'issue' => 'attention', 'dates' => 'all'], false)],
            ['key' => 'paymentReview', 'count' => DB::table('payment_transactions')->whereIn('status', ['pending_review', 'under_review'])->count(), 'url' => route('admin.payments.index', ['tab' => 'review'], false)],
            ['key' => 'refundReview', 'count' => DB::table('payment_refunds')->whereIn('status', ['requested', 'under_review'])->count(), 'url' => route('admin.payments.index', ['tab' => 'refunds', 'refund_status' => 'pending'], false)],
            ['key' => 'supportReply', 'count' => DB::table('support_conversations')->where('status', '!=', 'resolved')->whereRaw('COALESCE(last_customer_message_id,0) > COALESCE(last_admin_reply_id,0)')->count(), 'url' => route('admin.support.index', ['tab' => 'needs_reply'], false)],
            ['key' => 'workerAttention', 'count' => $workers['counts']['attention'], 'url' => route('admin.jobs.workers', ['scope' => 'current'], false)],
            ['key' => 'scheduleAttention', 'count' => $failing + ($scheduler['state'] === 'stopped' ? 1 : 0), 'url' => route('admin.schedules.index', absolute: false)],
        ];

        return ['filters' => $filters, 'summary' => [...$summary, 'active' => $active], 'previous' => $previous,
            'range' => ['from' => $bounds['start']->format('Y-m-d'), 'to' => $bounds['end']->subDay()->format('Y-m-d'), 'timezone' => $bounds['timezone']],
            'today' => CarbonImmutable::now($bounds['timezone'])->format('Y-m-d'), 'updated_at' => now()->toIso8601String(),
            'trend' => $this->trend(clone $finance, $bounds), 'modes' => $modes, 'packages' => $packages, 'package_total' => $packageTotal,
            'recent' => $recent, 'attention' => $attention,
            'system' => ['workers' => $workers['counts'], 'processing' => $workers['processingState'], 'storage' => $storage, 'budget' => $budget,
                'scheduler' => ['state' => $scheduler['state'], 'failed' => $failing], 'provider' => $providerState, 'provider_checked_at' => $check?->checked_at ? CarbonImmutable::parse($check->checked_at, 'UTC')->toIso8601String() : null],
            'links' => ['index' => route('admin.dashboard', absolute: false), 'users' => route('admin.users.index', absolute: false),
                'payments' => route('admin.payments.index', absolute: false), 'revenue' => route('admin.revenue.index', absolute: false),
                'processing' => route('admin.processing.index', absolute: false), 'jobs' => route('admin.jobs.index', absolute: false),
                'workers' => route('admin.jobs.workers', absolute: false), 'schedules' => route('admin.schedules.index', absolute: false), 'usage' => route('admin.usage.index', absolute: false),
                'support' => route('admin.support.index', absolute: false), 'storage' => route('admin.settings.storage', absolute: false),
                'providers' => route('admin.settings.providers', absolute: false), 'notifications' => route('admin.notifications.index', absolute: false)]];
    }
}
