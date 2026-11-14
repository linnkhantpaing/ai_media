<?php

namespace App\Services;

use App\Models\CustomerPackageAssignment;
use App\Models\MediaProject;
use App\Models\Package;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProcessingReport
{
    public function bounds(array $filters): array
    {
        $zone = app(GeneralSettings::class)->publicData()['timezone'] ?? 'UTC';
        $today = CarbonImmutable::now($zone)->startOfDay();
        $end = $today->addDay();
        $start = match ($filters['dates']) {
            '1' => $today, '7' => $today->subDays(6), '30' => $today->subDays(29),
            'month' => $today->startOfMonth(), 'last_month' => $today->subMonthNoOverflow()->startOfMonth(),
            'custom' => CarbonImmutable::parse($filters['from'], $zone)->startOfDay(),
            default => CarbonImmutable::parse($this->events()->where('e.occurred_at', '<=', now())->min('e.occurred_at') ?? $today, 'UTC')->setTimezone($zone)->startOfDay(),
        };
        if ($filters['dates'] === 'last_month') {
            $end = $today->startOfMonth();
        } elseif ($filters['dates'] === 'custom') {
            $end = CarbonImmutable::parse($filters['to'], $zone)->startOfDay()->addDay();
        }
        if ($start >= $end) {
            throw ValidationException::withMessages(['to' => 'Choose an end date on or after the start date.']);
        }

        return ['from' => $start->utc(), 'until' => $end->utc(), 'start' => $start, 'end' => $end, 'timezone' => $zone];
    }

    /** Historical context comes from the operation, never today's package definition. */
    public function events(): Builder
    {
        $charges = DB::table('usage_events as ev')->join('usage_records as ur', 'ur.id', '=', 'ev.usage_record_id')
            ->leftJoin('media_projects as p', 'p.id', '=', 'ur.media_project_id')
            ->leftJoin('media_runs as r', 'r.id', '=', 'ur.media_run_id')
            ->where('ur.kind', 'processing')->whereIn('ev.kind', ['charged', 'adjusted'])
            ->select(['ur.user_id', 'ur.media_project_id as project_id', 'ur.package_id', 'ur.package_snapshot',
                'p.source_type as source', 'r.settings->mode as mode', 'ev.created_at as occurred_at',
                'ev.kind as metric', 'ev.changes->seconds as seconds'])
            ->selectRaw('NULL AS task_id, NULL AS stage, NULL AS outcome, NULL AS duration, NULL AS title, NULL AS output');

        $tasks = DB::table('media_tasks as t')->join('media_projects as p', 'p.id', '=', 't.media_project_id')
            ->leftJoin('usage_records as ur', 'ur.media_run_id', '=', 't.media_run_id')
            ->leftJoin('media_runs as r', 'r.id', '=', 't.media_run_id')
            ->whereIn('t.status', ['completed', 'failed', 'cancelled'])->whereNotNull('t.finished_at')
            ->select(['p.user_id', 'p.id as project_id', 'ur.package_id', 'ur.package_snapshot',
                'p.source_type as source', 't.mode', 't.finished_at as occurred_at'])
            ->selectRaw("'task' AS metric, 0 AS seconds, t.id AS task_id, t.type AS stage, t.status AS outcome,
                CASE WHEN t.started_at IS NOT NULL AND t.started_at <= t.finished_at AND t.processing_seconds >= 0 THEN t.processing_seconds ELSE NULL END AS duration")
            ->addSelect(['p.title', 'r.settings->output as output']);

        $assignment = DB::table('customer_package_assignments as a')->select('a.id')
            ->whereColumn('a.user_id', 'p.user_id')->whereColumn('a.starts_at', '<=', 'p.created_at')
            ->whereColumn('a.ends_at', '>', 'p.created_at')
            ->where(fn ($q) => $q->whereNull('a.closed_at')->orWhereColumn('a.closed_at', '>', 'p.created_at'))
            ->orderByDesc('a.starts_at')->orderByDesc('a.id')->limit(1);
        $created = DB::query()->fromSub(DB::table('media_projects as p')->select(['p.id', 'p.user_id', 'p.source_type', 'p.created_at', 'p.settings->mode as mode'])
            ->selectSub($assignment, 'assignment_id'), 'project_context')
            ->leftJoin('customer_package_assignments as a', 'a.id', '=', 'project_context.assignment_id')
            ->select(['project_context.user_id', 'project_context.id as project_id', 'a.package_id', 'a.snapshot as package_snapshot',
                'project_context.source_type as source', 'project_context.mode', 'project_context.created_at as occurred_at'])
            ->selectRaw("'project' AS metric, 0 AS seconds, NULL AS task_id, NULL AS stage, NULL AS outcome, NULL AS duration, NULL AS title, NULL AS output");

        return DB::query()->fromSub($charges->unionAll($tasks)->unionAll($created), 'e')
            ->join('users as u', 'u.id', '=', 'e.user_id')->where('u.is_admin', false);
    }

    private function scoped(Builder $query, array $filters, string $prefix = 'e'): Builder
    {
        foreach (['customer' => 'user_id', 'package' => 'package_id', 'mode' => 'mode', 'source' => 'source'] as $key => $column) {
            if ($filters[$key] !== '') {
                $query->where($prefix.'.'.$column, $filters[$key]);
            }
        }

        return $query;
    }

    private function period(array $filters, array $bounds): Builder
    {
        return $this->scoped($this->events(), $filters)->where('e.occurred_at', '>=', $bounds['from'])
            ->where('e.occurred_at', '<', $bounds['until'])->where('e.occurred_at', '<=', now());
    }

    private function emptyMetrics(): array
    {
        return ['projects' => 0, 'seconds' => 0, 'successful' => 0, 'failed' => 0, 'cancelled' => 0,
            'duration_sum' => 0.0, 'duration_count' => 0, 'storage' => 0, 'reserved' => 0];
    }

    private function metric(array &$metrics, object $event): void
    {
        if (in_array($event->metric, ['charged', 'adjusted'], true)) {
            $metrics['seconds'] += ($event->metric === 'adjusted' ? -1 : 1) * (int) $event->seconds;
        } elseif ($event->metric === 'task' && $event->stage === 'generate') {
            $key = $event->outcome === 'completed' ? 'successful' : $event->outcome;
            $metrics[$key]++;
            if ($key === 'successful' && $event->duration !== null) {
                $metrics['duration_sum'] += (float) $event->duration;
                $metrics['duration_count']++;
            }
        }
    }

    private function finished(array $metrics): array
    {
        $metrics['average_duration'] = $metrics['duration_count'] ? round($metrics['duration_sum'] / $metrics['duration_count'], 2) : null;
        $denominator = $metrics['successful'] + $metrics['failed'];
        $metrics['success_rate'] = $denominator ? round($metrics['successful'] * 100 / $denominator, 1) : null;
        unset($metrics['duration_sum'], $metrics['duration_count']);

        return $metrics;
    }

    private function snapshot(?string $json): ?array
    {
        $value = $json ? json_decode($json, true) : null;

        return is_array($value) ? ['name' => (string) ($value['name'] ?? ''), 'version' => (int) ($value['version'] ?? 1)] : null;
    }

    /** Current stock ignores historical date bounds; package follows the currently selected subscription. */
    private function stock(array $filters): Builder
    {
        $current = CustomerPackageAssignment::query()->current()->select(['user_id', 'package_id'])->toBase();

        return DB::table('media_projects as p')->join('users as u', 'u.id', '=', 'p.user_id')
            ->leftJoinSub($current, 'a', 'a.user_id', '=', 'p.user_id')->where('u.is_admin', false)
            ->when($filters['customer'] !== '', fn ($q) => $q->where('p.user_id', $filters['customer']))
            ->when($filters['package'] !== '', fn ($q) => $q->where('a.package_id', $filters['package']))
            ->when($filters['mode'] !== '', fn ($q) => $q->where('p.settings->mode', $filters['mode']))
            ->when($filters['source'] !== '', fn ($q) => $q->where('p.source_type', $filters['source']));
    }

    public function report(array $filters, ?int $detailCustomer = null): array
    {
        return DB::transaction(function () use ($filters, $detailCustomer): array {
            $bounds = $this->bounds($filters);
            $summary = $this->emptyMetrics();
            $customers = $packages = $stages = $modes = $sources = $projects = $trend = $recent = [];
            $days = (int) ceil($bounds['start']->diffInDays($bounds['end']));
            $group = $days <= 45 ? 'day' : ($days <= 210 ? 'week' : 'month');
            $dateKey = fn (CarbonImmutable $date) => match ($group) {
                'week' => $date->startOfWeek()->toDateString(), 'month' => $date->startOfMonth()->toDateString(), default => $date->toDateString(),
            };
            $cursor = $bounds['start'];
            while ($cursor < $bounds['end']) {
                $key = $dateKey($cursor);
                $trend[$key] = ['date' => $key, 'seconds' => 0, 'exports' => 0];
                $cursor = match ($group) {
                    'week' => $cursor->startOfWeek()->addWeek(), 'month' => $cursor->startOfMonth()->addMonth(), default => $cursor->addDay(),
                };
            }
            foreach ($this->period($filters, $bounds)->select('e.*')->orderByDesc('e.occurred_at')->cursor() as $event) {
                $id = (int) $event->user_id;
                $customers[$id] ??= ['id' => $id, ...$this->emptyMetrics(), 'packages' => []];
                $packageKey = $event->package_id === null ? 'unknown' : (string) $event->package_id;
                $captured = $this->snapshot($event->package_snapshot);
                $packages[$packageKey] ??= ['id' => $packageKey, ...$this->emptyMetrics(), 'customers' => []];
                if ($captured) {
                    $customers[$id]['packages'][$packageKey.':'.$captured['version']] = $captured;
                }
                $packages[$packageKey]['customers'][$id] = true;
                foreach (['summary', 'customer', 'package'] as $target) {
                    if ($target === 'summary') {
                        $this->metric($summary, $event);
                    } elseif ($target === 'customer') {
                        $this->metric($customers[$id], $event);
                    } else {
                        $this->metric($packages[$packageKey], $event);
                    }
                }
                if ($event->project_id !== null) {
                    $projects[$event->project_id] ??= ['user_id' => $id, 'source' => $event->source ?? 'unknown', 'packages' => []];
                    $projects[$event->project_id]['packages'][$packageKey] = true;
                }
                $key = $dateKey(CarbonImmutable::parse($event->occurred_at, 'UTC')->setTimezone($bounds['timezone']));
                if (in_array($event->metric, ['charged', 'adjusted'], true)) {
                    $delta = ($event->metric === 'adjusted' ? -1 : 1) * (int) $event->seconds;
                    $trend[$key]['seconds'] += $delta;
                    $mode = in_array($event->mode, ['subs', 'dub'], true) ? $event->mode : 'unknown';
                    $modes[$mode] = ($modes[$mode] ?? 0) + $delta;
                }
                if ($event->metric === 'task') {
                    $stage = $event->stage;
                    $stages[$stage] ??= ['id' => $stage, 'completed' => 0, 'failed' => 0, 'cancelled' => 0, 'duration_sum' => 0.0, 'duration_count' => 0];
                    $stages[$stage][$event->outcome]++;
                    if ($event->outcome === 'completed' && $event->duration !== null) {
                        $stages[$stage]['duration_sum'] += (float) $event->duration;
                        $stages[$stage]['duration_count']++;
                    }
                    if ($stage === 'generate') {
                        if ($event->outcome === 'completed') {
                            $trend[$key]['exports']++;
                        }
                        if ($detailCustomer !== null && count($recent) < 20) {
                            $recent[] = ['id' => (int) $event->task_id, 'project_id' => $event->project_id, 'title' => $event->title, 'status' => $event->outcome,
                                'mode' => $event->mode ?? 'unknown', 'source' => $event->source ?? 'unknown',
                                'output' => $event->output, 'duration' => $event->duration === null ? null : (float) $event->duration,
                                'at' => CarbonImmutable::parse($event->occurred_at, 'UTC')->toIso8601String(),
                                'package' => $captured, 'job_url' => route('admin.jobs.index', ['q' => 'J-'.str_pad((string) $event->task_id, 4, '0', STR_PAD_LEFT), 'date' => 'all'], absolute: false)];
                        }
                    }
                }
            }
            foreach ($projects as $project) {
                $summary['projects']++;
                $customers[$project['user_id']]['projects']++;
                $sources[$project['source']] = ($sources[$project['source']] ?? 0) + 1;
                foreach ($project['packages'] as $key => $_) {
                    $packages[$key]['projects']++;
                }
            }
            $stock = $this->stock($filters)->join('media_files as f', 'f.media_project_id', '=', 'p.id')
                ->whereNotNull('f.verified_at')->where('f.state', '!=', 'deleted')->select('p.user_id')->selectRaw('SUM(f.bytes) AS bytes')->groupBy('p.user_id')->get();
            foreach ($stock as $row) {
                $id = (int) $row->user_id;
                $customers[$id] ??= ['id' => $id, ...$this->emptyMetrics(), 'packages' => []];
                $customers[$id]['storage'] = (int) $row->bytes;
                $summary['storage'] += (int) $row->bytes;
            }
            $reserved = DB::table('usage_records as e')->leftJoin('media_projects as p', 'p.id', '=', 'e.media_project_id')
                ->leftJoin('media_runs as r', 'r.id', '=', 'e.media_run_id')->join('users as u', 'u.id', '=', 'e.user_id')
                ->where('u.is_admin', false)->where('e.kind', 'processing')->where('e.status', 'reserved')
                ->when($filters['customer'] !== '', fn ($q) => $q->where('e.user_id', $filters['customer']))
                ->when($filters['package'] !== '', fn ($q) => $q->where('e.package_id', $filters['package']))
                ->when($filters['mode'] !== '', fn ($q) => $q->where('r.settings->mode', $filters['mode']))
                ->when($filters['source'] !== '', fn ($q) => $q->where('p.source_type', $filters['source']))
                ->select('e.user_id')->selectRaw('SUM(e.reserved_seconds) AS seconds')->groupBy('e.user_id')->get();
            foreach ($reserved as $row) {
                $id = (int) $row->user_id;
                $customers[$id] ??= ['id' => $id, ...$this->emptyMetrics(), 'packages' => []];
                $customers[$id]['reserved'] = (int) $row->seconds;
                $summary['reserved'] += (int) $row->seconds;
            }
            $identities = User::whereIn('id', array_keys($customers))->get(['id', 'name', 'email'])->keyBy('id');
            $customerRows = [];
            foreach ($customers as $id => $row) {
                if (! isset($identities[$id])) {
                    continue;
                }
                $row['name'] = $identities[$id]->name;
                $row['email'] = $identities[$id]->email;
                $row['packages'] = array_values($row['packages']);
                $row['detail_url'] = route('admin.processing.show', $id, absolute: false);
                $customerRows[] = $this->finished($row);
            }
            $packageNames = Package::query()->pluck('name', 'id');
            foreach ($packages as $id => &$row) {
                $row['name'] = $packageNames[$id] ?? null;
                $row['customers'] = count($row['customers']);
                $row = $this->finished($row);
            }
            unset($row);
            foreach ($stages as &$row) {
                $row['average_duration'] = $row['duration_count'] ? round($row['duration_sum'] / $row['duration_count'], 2) : null;
                unset($row['duration_sum'], $row['duration_count']);
            }
            unset($row);
            usort($customerRows, fn ($a, $b) => ($b['seconds'] <=> $a['seconds']) ?: strcasecmp($a['name'], $b['name']));
            $top = array_slice(array_filter($customerRows, fn ($row) => $row['seconds'] > 0), 0, 3);
            $allRows = match ($filters['view']) {
                'packages' => array_values($packages), 'stages' => array_values($stages), default => $customerRows,
            };
            if ($filters['q'] !== '') {
                $search = mb_strtolower(trim($filters['q']));
                $allRows = array_values(array_filter($allRows, fn ($row) => str_contains(mb_strtolower(($row['name'] ?? '').' '.($row['email'] ?? '').' '.($row['id'] ?? '')), $search)));
            }
            $sort = $filters['sort'];
            usort($allRows, function ($a, $b) use ($sort, $filters): int {
                if ($sort === 'name') {
                    return strcasecmp((string) ($a['name'] ?? $a['id']), (string) ($b['name'] ?? $b['id']));
                }
                $key = match ($sort) {
                    'exports' => $filters['view'] === 'stages' ? 'completed' : 'successful', 'failures' => 'failed', 'storage' => 'storage', default => 'seconds'
                };

                return (($b[$key] ?? 0) <=> ($a[$key] ?? 0)) ?: strcasecmp((string) ($a['name'] ?? $a['id']), (string) ($b['name'] ?? $b['id']));
            });
            $total = count($allRows);
            $pages = max(1, (int) ceil($total / $filters['per_page']));
            $page = min($pages, $filters['page']);
            $items = array_slice($allRows, ($page - 1) * $filters['per_page'], $filters['per_page']);
            $result = ['filters' => $filters, 'summary' => $this->finished($summary), 'trend' => ['group' => $group, 'points' => array_values($trend)],
                'modes' => $modes, 'sources' => $sources, 'top' => $top, 'top_total' => array_sum(array_map(fn ($row) => max(0, $row['seconds']), $customerRows)), 'items' => $items,
                'range' => ['from' => $bounds['start']->toDateString(), 'to' => $bounds['end']->subDay()->toDateString(), 'timezone' => $bounds['timezone']],
                'today' => CarbonImmutable::now($bounds['timezone'])->toDateString(), 'updated_at' => now()->toIso8601String(),
                'pagination' => ['page' => $page, 'pages' => $pages, 'total' => $total, 'from' => $total ? ($page - 1) * $filters['per_page'] + 1 : 0, 'to' => $total ? ($page - 1) * $filters['per_page'] + count($items) : 0],
                'endpoints' => ['index' => route('admin.processing.index', absolute: false), 'export' => route('admin.processing.export', absolute: false)]];
            if ($detailCustomer !== null) {
                $user = User::where('is_admin', false)->findOrFail($detailCustomer);
                $assignment = CustomerPackageAssignment::query()->current()->where('user_id', $user->id)->first();
                $snapshot = $assignment?->effectiveSnapshot();
                $posters = MediaProject::whereIn('id', array_column($recent, 'project_id'))
                    ->get(['id', 'metadata->poster as has_poster', 'metadata->duration as source_duration'])->keyBy('id');
                foreach ($recent as &$entry) {
                    $poster = $posters[$entry['project_id']] ?? null;
                    $entry['poster_url'] = $poster && in_array($poster->has_poster, [true, 1, '1', 'true'], true)
                        ? route('admin.jobs.file', ['mediaTask' => $entry['id'], 'kind' => 'poster'], absolute: false) : null;
                    $entry['source_duration'] = $poster && is_numeric($poster->source_duration) && (float) $poster->source_duration > 0
                        ? (float) $poster->source_duration : null;
                    unset($entry['project_id']);
                }
                unset($entry);

                return ['customer' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
                    'summary' => $result['summary'], 'range' => $result['range'], 'recent' => $recent,
                    'current' => $snapshot ? ['name' => $snapshot['name'] ?? '', 'type' => $snapshot['type'] ?? null, 'version' => $snapshot['version'] ?? 1,
                        'status' => $assignment->status(), 'from' => $assignment->starts_at->toIso8601String(), 'to' => $assignment->ends_at->toIso8601String()] : null,
                    'ledger_url' => route('admin.usage.index', ['q' => $user->email], absolute: false),
                    'jobs_url' => route('admin.jobs.index', ['q' => $user->email, 'date' => 'all'], absolute: false)];
            }

            return [...$result, '_export' => $allRows];
        });
    }
}
