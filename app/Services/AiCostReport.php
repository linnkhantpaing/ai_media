<?php

namespace App\Services;

use App\Models\MediaProject;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * AI & Storage Costs report: Gemini tokens and estimated cost per day, step, customer and
 * video, current R2 storage, and revenue vs cost per customer. All money is MMK.
 */
class AiCostReport
{
    private const TOKENS = ['input_tokens', 'audio_input_tokens', 'cached_tokens', 'output_tokens', 'thought_tokens', 'total_tokens'];

    public function __construct(private AiCostSettings $prices) {}

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: string} start, end (exclusive) and timezone */
    public function range(array $filters): array
    {
        $timezone = (string) (app(GeneralSettings::class)->current()['configuration']['timezone'] ?? config('app.timezone', 'UTC'));
        $today = CarbonImmutable::now($timezone)->startOfDay();
        [$start, $end] = match ($filters['range'] ?? '7') {
            '1' => [$today, $today->addDay()],
            '30' => [$today->subDays(29), $today->addDay()],
            '90' => [$today->subDays(89), $today->addDay()],
            'custom' => [CarbonImmutable::parse($filters['from'], $timezone)->startOfDay(), CarbonImmutable::parse($filters['to'], $timezone)->startOfDay()->addDay()],
            default => [$today->subDays(6), $today->addDay()],
        };

        return [$start, $end, $timezone];
    }

    public function build(array $filters): array
    {
        [$start, $end, $timezone] = $this->range($filters);
        $settings = $this->prices->current();
        $rows = $this->rows($start, $end, $timezone);
        $missing = [];
        $cost = function (object $row) use ($settings, &$missing): float {
            $usd = $this->prices->usd($row->model, (array) $row, $settings);
            if ($usd === null) {
                $missing[$row->model] = true;
            }

            return (float) ($this->prices->toMmk($usd ?? 0.0, 'USD', $settings) ?? 0.0);
        };
        foreach ($rows as $row) {
            $row->mmk = $cost($row);
        }
        $customerRows = $rows->filter(fn ($row) => $row->context !== 'check');
        $days = max(1, (int) $start->diffInDays($end));
        $storage = $this->storage($start, $end, $timezone);
        $storageRate = (float) ($this->prices->toMmk((float) $settings['storage_gb_month'], 'USD', $settings) ?? 0.0);
        $storageMmk = fn (float $byteDays): float => $byteDays / 1073741824 / 30 * $storageRate;
        $projects = $this->projectStates($customerRows->pluck('media_project_id')->filter()->unique()->values());
        $wasted = $customerRows->filter(fn ($row) => in_array($projects[$row->media_project_id]['state'] ?? null, ['failed', 'cancelled', 'not_exported'], true));
        $revenue = $this->revenue($start, $end, $settings);

        $daily = [];
        for ($day = $start; $day < $end; $day = $day->addDay()) {
            $key = $day->toDateString();
            $dayRows = $rows->where('day', $key);
            $daily[] = ['day' => $key, 'ai_mmk' => round($dayRows->sum('mmk'), 2), 'tokens' => (int) $dayRows->sum('total_tokens'),
                'storage_mmk' => round($storageMmk((float) ($storage['daily'][$key] ?? $storage['now'])), 2)];
        }
        $stages = $rows->groupBy(fn ($row) => $this->stage($row->context, $row->operation))->map(fn (Collection $group, string $key) => [
            'key' => $key, 'calls' => (int) $group->sum('calls'), 'tokens' => (int) $group->sum('total_tokens'), 'ai_mmk' => round($group->sum('mmk'), 2),
        ])->sortByDesc('ai_mmk')->values()->all();
        $aiMmk = $customerRows->sum('mmk');
        $storageTotal = array_sum(array_map($storageMmk, array_map('floatval', $storage['daily'])));

        return [
            'range' => ['start' => $start->toDateString(), 'end' => $end->subDay()->toDateString(), 'days' => $days, 'timezone' => $timezone],
            'summary' => [
                'calls' => (int) $rows->sum('calls'), 'input_tokens' => (int) $rows->sum('input_tokens'), 'output_tokens' => (int) ($rows->sum('output_tokens') + $rows->sum('thought_tokens')),
                'total_tokens' => (int) $rows->sum('total_tokens'), 'ai_mmk' => round($rows->sum('mmk'), 2), 'check_mmk' => round($rows->where('context', 'check')->sum('mmk'), 2),
                'storage_bytes' => $storage['now'], 'storage_added' => $storage['added'], 'storage_removed' => $storage['removed'], 'storage_mmk' => round($storageTotal, 2),
                'wasted_mmk' => round($wasted->sum('mmk'), 2), 'wasted_tokens' => (int) $wasted->sum('total_tokens'),
                'revenue_mmk' => round(array_sum($revenue), 2), 'profit_mmk' => round(array_sum($revenue) - $aiMmk - $storageTotal, 2),
            ],
            'daily' => $daily, 'stages' => $stages,
            'customers' => $this->customers($customerRows, $storage['by_user'], $revenue, $storageMmk, $days, $filters),
            'videos' => $this->videos($customerRows, $projects, $filters),
            'pricing' => ['missing_models' => array_keys($missing), 'rates_missing' => $settings['usd_mmk'] === null, 'configured' => $settings['revision'] > 0],
        ];
    }

    /** One project's individual AI calls (detail kept for 90 days). */
    public function project(string $projectId): array
    {
        $settings = $this->prices->current();
        $project = MediaProject::with('user')->find($projectId);
        $calls = DB::table('ai_usage_events')->where('media_project_id', $projectId)->orderBy('created_at')->get()->map(function ($row) use ($settings) {
            $usd = $this->prices->usd($row->model, (array) $row, $settings);

            return ['at' => CarbonImmutable::parse($row->created_at)->toIso8601String(), 'stage' => $this->stage($row->context, $row->operation), 'model' => $row->model,
                'input_tokens' => (int) $row->input_tokens, 'audio_input_tokens' => (int) $row->audio_input_tokens, 'output_tokens' => (int) $row->output_tokens + (int) $row->thought_tokens,
                'total_tokens' => (int) $row->total_tokens, 'ai_mmk' => $usd === null ? null : round((float) $this->prices->toMmk($usd, 'USD', $settings), 2)];
        });

        return ['project' => ['id' => $projectId, 'title' => $project?->title, 'customer' => $project?->user ? ['name' => $project->user->name, 'email' => $project->user->email] : null,
            'state' => $this->projectStates(collect([$projectId]))[$projectId]['state'] ?? 'deleted'], 'calls' => $calls->all(),
            'total_tokens' => (int) $calls->sum('total_tokens'), 'ai_mmk' => round((float) $calls->sum('ai_mmk'), 2), 'retention_days' => AiUsage::RETENTION_DAYS];
    }

    /** Usage grouped per local day, customer, project, step and model: recent call rows plus the permanent daily roll-ups. */
    private function rows(CarbonImmutable $start, CarbonImmutable $end, string $timezone): Collection
    {
        $offset = $start->utcOffset() * 60;
        $day = DB::getDriverName() === 'sqlite' ? "date(created_at, '".($offset >= 0 ? '+' : '').$offset." seconds')" : 'DATE(DATE_ADD(created_at, INTERVAL '.$offset.' SECOND))';
        $sums = 'COUNT(*) as calls, '.implode(', ', array_map(fn ($c) => "SUM($c) as $c", self::TOKENS));
        $recent = DB::table('ai_usage_events')->where('created_at', '>=', $start->utc())->where('created_at', '<', $end->utc())
            ->selectRaw("$day as day, user_id, media_project_id, context, operation, model, $sums")
            ->groupByRaw("$day, user_id, media_project_id, context, operation, model")->get();
        $old = DB::table('ai_usage_daily')->where('day', '>=', $start->toDateString())->where('day', '<', $end->toDateString())
            ->select(['day', 'user_id', 'media_project_id', 'context', 'operation', 'model', 'calls', ...self::TOKENS])->get();

        return $recent->concat($old)->map(function ($row) {
            $row->day = substr((string) $row->day, 0, 10);
            foreach (['calls', ...self::TOKENS] as $column) {
                $row->{$column} = (int) $row->{$column};
            }

            return $row;
        })->values();
    }

    /**
     * Stored bytes: now, per customer now, and an end-of-day estimate for each day in the range
     * (today's total minus the storage changes recorded after that day).
     */
    private function storage(CarbonImmutable $start, CarbonImmutable $end, string $timezone): array
    {
        $byUser = DB::table('media_files')->join('media_projects', 'media_projects.id', '=', 'media_files.media_project_id')
            ->where('media_files.state', '!=', 'deleted')->groupBy('media_projects.user_id')->selectRaw('media_projects.user_id as user_id, SUM(media_files.bytes) as bytes')
            ->pluck('bytes', 'user_id')->map(fn ($bytes) => (int) $bytes)->all();
        $now = array_sum($byUser);
        $changes = DB::table('usage_events')->join('usage_records', 'usage_records.id', '=', 'usage_events.usage_record_id')->where('usage_records.kind', 'storage')
            ->where('usage_events.created_at', '>=', $start->utc())->get(['usage_events.changes', 'usage_events.created_at']);
        $perDay = [];
        $added = 0;
        $removed = 0;
        foreach ($changes as $change) {
            $bytes = (int) (json_decode((string) $change->changes, true)['bytes'] ?? 0);
            $key = CarbonImmutable::parse($change->created_at, 'UTC')->setTimezone($timezone)->toDateString();
            $perDay[$key] = ($perDay[$key] ?? 0) + $bytes;
            if (CarbonImmutable::parse($change->created_at, 'UTC') < $end->utc()) {
                $bytes > 0 ? $added += $bytes : $removed -= $bytes;
            }
        }
        $daily = [];
        $today = CarbonImmutable::now($timezone)->toDateString();
        for ($day = $start; $day < $end; $day = $day->addDay()) {
            $key = $day->toDateString();
            if ($key > $today) {
                continue;
            }
            $later = array_sum(array_filter($perDay, fn ($bytes, $date) => $date > $key, ARRAY_FILTER_USE_BOTH));
            $daily[$key] = max(0, $now - $later);
        }

        return ['now' => $now, 'by_user' => $byUser, 'daily' => $daily, 'added' => $added, 'removed' => $removed];
    }

    /** Approved payments minus completed refunds in the range, per customer, in MMK. */
    private function revenue(CarbonImmutable $start, CarbonImmutable $end, array $settings): array
    {
        $result = [];
        foreach (DB::table('payment_transactions')->where('status', 'approved')->where('approved_at', '>=', $start->utc())->where('approved_at', '<', $end->utc())
            ->groupBy('user_id', 'currency')->selectRaw('user_id, currency, SUM(COALESCE(received_minor, expected_minor)) as amount')->get() as $row) {
            $result[$row->user_id] = ($result[$row->user_id] ?? 0) + (float) ($this->prices->toMmk($row->amount / 100, $row->currency, $settings) ?? 0);
        }
        foreach (DB::table('payment_refunds')->join('payment_transactions', 'payment_transactions.id', '=', 'payment_refunds.payment_transaction_id')
            ->where('payment_refunds.status', 'completed')->where('payment_refunds.completed_at', '>=', $start->utc())->where('payment_refunds.completed_at', '<', $end->utc())
            ->groupBy('payment_transactions.user_id', 'payment_transactions.currency')->selectRaw('payment_transactions.user_id as user_id, payment_transactions.currency as currency, SUM(payment_refunds.amount_minor) as amount')->get() as $row) {
            $result[$row->user_id] = ($result[$row->user_id] ?? 0) - (float) ($this->prices->toMmk($row->amount / 100, $row->currency, $settings) ?? 0);
        }

        return $result;
    }

    private function customers(Collection $rows, array $storage, array $revenue, callable $storageMmk, int $days, array $filters): array
    {
        $ids = $rows->pluck('user_id')->filter()->merge(array_keys($storage))->merge(array_keys($revenue))->unique()->values();
        $users = User::whereIn('id', $ids)->where('is_admin', false)->get(['id', 'name', 'email'])->keyBy('id');
        $term = mb_strtolower(trim((string) ($filters['q'] ?? '')));
        $list = $users->map(function (User $user) use ($rows, $storage, $revenue, $storageMmk, $days) {
            $own = $rows->where('user_id', $user->id);
            $ai = (float) $own->sum('mmk');
            $bytes = (int) ($storage[$user->id] ?? 0);
            $stored = $storageMmk((float) $bytes * $days);
            $income = (float) ($revenue[$user->id] ?? 0);

            return ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'videos' => $own->pluck('media_project_id')->filter()->unique()->count(),
                'tokens' => (int) $own->sum('total_tokens'), 'ai_mmk' => round($ai, 2), 'storage_bytes' => $bytes, 'storage_mmk' => round($stored, 2),
                'revenue_mmk' => round($income, 2), 'profit_mmk' => round($income - $ai - $stored, 2)];
        })->filter(fn (array $row) => $term === '' || str_contains(mb_strtolower($row['name'].' '.$row['email']), $term))
            ->sortByDesc(fn (array $row) => $row['ai_mmk'] + $row['storage_mmk'])->values();

        return $this->page($list, (int) ($filters['customers_page'] ?? 1));
    }

    private function videos(Collection $rows, array $projects, array $filters): array
    {
        $users = User::whereIn('id', $rows->pluck('user_id')->filter()->unique())->get(['id', 'name', 'email'])->keyBy('id');
        $term = mb_strtolower(trim((string) ($filters['q'] ?? '')));
        $list = $rows->whereNotNull('media_project_id')->groupBy('media_project_id')->map(function (Collection $group, string $id) use ($projects, $users) {
            $info = $projects[$id] ?? ['state' => 'deleted', 'title' => null, 'workflow' => null, 'seconds' => 0];
            $user = $users[$group->first()->user_id] ?? null;

            return ['id' => $id, 'title' => $info['title'], 'customer' => $user ? ['name' => $user->name, 'email' => $user->email] : null, 'workflow' => $info['workflow'],
                'source_seconds' => $info['seconds'], 'tokens' => (int) $group->sum('total_tokens'), 'ai_mmk' => round((float) $group->sum('mmk'), 2), 'state' => $info['state'],
                'last_day' => $group->max('day')];
        })->filter(fn (array $row) => $term === '' || str_contains(mb_strtolower(($row['title'] ?? '').' '.($row['customer']['name'] ?? '').' '.($row['customer']['email'] ?? '')), $term))
            ->sortByDesc('ai_mmk')->values();

        return $this->page($list, (int) ($filters['videos_page'] ?? 1));
    }

    /** completed (has an export) / in_progress / failed / cancelled / not_exported (script made, never exported) / deleted */
    private function projectStates(Collection $ids): array
    {
        $states = [];
        foreach (MediaProject::whereIn('id', $ids)->get() as $project) {
            $state = $project->output_path || $project->audio_path || $project->status === 'completed' ? 'completed'
                : ($project->busy() ? 'in_progress' : (in_array($project->status, ['failed', 'cancelled'], true) ? $project->status : 'not_exported'));
            $settings = is_array($project->settings) ? $project->settings : [];
            $states[$project->id] = ['state' => $state, 'title' => $project->title, 'workflow' => PackageCatalog::workflowOf($settings), 'seconds' => (int) ceil((float) ($project->metadata['duration'] ?? 0))];
        }

        return $states;
    }

    private function stage(string $context, string $operation): string
    {
        if ($context === 'check') {
            return 'check';
        }
        if ($context === 'voice_sample') {
            return 'voice_sample';
        }

        return match ($operation) {
            'speech' => $context === 'preview' ? 'voice_preview' : 'narration_voice',
            'watch' => 'movie_watch', 'plan' => 'movie_plan', 'transcribe' => 'transcribe', 'translate' => 'translate',
            'fit' => 'narration_fit', 'rewrite' => 'rewrite', default => 'other',
        };
    }

    private function page(Collection $items, int $page, int $size = 15): array
    {
        $pages = max(1, (int) ceil($items->count() / $size));
        $page = min(max(1, $page), $pages);

        return ['data' => $items->slice(($page - 1) * $size, $size)->values()->all(), 'page' => $page, 'pages' => $pages, 'total' => $items->count()];
    }
}
