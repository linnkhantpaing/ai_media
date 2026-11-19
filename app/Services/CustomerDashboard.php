<?php

namespace App\Services;

use App\Models\MediaPreset;
use App\Models\MediaProject;
use App\Models\MediaRun;
use App\Models\PaymentTransaction;
use App\Models\UsageRecord;
use App\Models\User;
use App\Services\Media\ProjectLibrary;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Read-only aggregation for the customer dashboard. Every figure comes from
 * the same sources as My Plan, All Projects, Billing and Notifications.
 */
class CustomerDashboard
{
    public const SECTIONS = ['plan', 'actions', 'work', 'recent', 'checklist', 'billing', 'notifications', 'usage'];

    private const RUNNING = ['analyzing', 'translating', 'rendering', 'cancel_requested'];

    private const FAILED = ['failed', 'import_failed'];

    private const DRAFT = ['ready', 'uploading', 'importing', 'validating'];

    public function __construct(private CustomerPlan $plans, private CustomerBilling $billing, private CustomerNotifications $notifications, private CustomerProfile $profile, private GeneralSettings $settings) {}

    /**
     * Builds the requested sections. A section that fails is reported in
     * "errors" so the page can show a retry inside that card only.
     *
     * @param  list<string>  $sections
     * @return array<string, mixed>
     */
    public function build(User $user, array $sections, string $range = '14'): array
    {
        $result = ['errors' => []];
        $overview = null;
        $plan = function () use ($user, &$overview): array {
            return $overview ??= $this->plans->overview($user);
        };
        foreach ($sections as $section) {
            try {
                $result[$section] = match ($section) {
                    'plan' => $this->plan($plan(), $user),
                    'actions' => $this->actions($user, $plan()),
                    'work' => $this->work($user),
                    'recent' => $this->recent($user),
                    'checklist' => $this->checklist($user),
                    'billing' => $this->billingSnapshot($user),
                    'notifications' => $this->notifications->feed($user),
                    'usage' => $this->usage($user, $range),
                };
            } catch (Throwable $exception) {
                report($exception);
                $result[$section] = null;
                $result['errors'][] = $section;
            }
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public function plan(array $overview, User $user): array
    {
        $preset = $user->default_preset_id ? MediaPreset::where('user_id', $user->id)->find($user->default_preset_id) : null;
        $features = $overview['package'] && ! $overview['testing'] ? $overview['features'] : null;

        return [
            ...collect($overview)->only(['package', 'status', 'expiring', 'start', 'end', 'days_left', 'next', 'pending_payment', 'processing', 'videos', 'storage', 'jobs', 'testing'])->all(),
            'workflows' => ['movie' => $features['movie'] ?? true, 'translate' => $features['dub'] ?? true, 'subtitles' => $features['subs'] ?? true],
            'preset' => $preset ? ['id' => $preset->id, 'name' => $preset->name] : null,
        ];
    }

    /**
     * Things the customer should act on, most urgent first.
     *
     * @return list<array<string, mixed>>
     */
    public function actions(User $user, array $overview): array
    {
        $billing = route('account.billing', absolute: false);
        $items = [];
        $name = $overview['package']['name'] ?? null;
        if (in_array($overview['status'], ['expired', 'ended', 'cancelled'], true) && $name) {
            $items[] = ['kind' => 'plan_expired', 'tone' => 'red', 'data' => ['package' => $name, 'date' => $overview['end']], 'url' => $billing];
        } elseif ($overview['status'] === 'none' && ! $overview['testing']) {
            $items[] = ['kind' => 'no_plan', 'tone' => 'amber', 'data' => [], 'url' => $billing];
        }
        $info = PaymentTransaction::where('user_id', $user->id)->where('status', 'needs_information')->latest('id')->first();
        if ($info) {
            $items[] = ['kind' => 'payment_info', 'tone' => 'red', 'data' => ['code' => $this->code($info->id)], 'url' => $billing.'?payment='.$info->id];
        }
        $failed = MediaProject::where('user_id', $user->id)->whereIn('status', self::FAILED)->where('updated_at', '>=', now()->subDays(14));
        $failedCount = (clone $failed)->count();
        if ($failedCount) {
            $first = $failed->latest('updated_at')->first();
            $items[] = ['kind' => 'export_failed', 'tone' => 'red', 'data' => ['count' => $failedCount, 'project' => $first->title],
                'url' => $failedCount === 1 ? $this->destination($first) : route('projects.index', ['status' => 'needs_attention'], false), 'key' => $first->id.':'.$first->updated_at->getTimestamp()];
        }
        $reviews = MediaProject::where('user_id', $user->id)->where('status', 'awaiting_review');
        $reviewCount = (clone $reviews)->count();
        if ($reviewCount) {
            $first = $reviews->latest('updated_at')->first();
            $items[] = ['kind' => 'scripts_ready', 'tone' => 'purple', 'data' => ['count' => $reviewCount, 'project' => $first->title],
                'url' => $reviewCount === 1 ? route('media.analysis', $first->id, false) : route('projects.index', ['status' => 'awaiting_review'], false)];
        }
        if ($overview['expiring'] && ! $overview['next']) {
            $items[] = ['kind' => 'plan_expiring', 'tone' => 'amber', 'data' => ['package' => $name, 'days' => $overview['days_left']], 'url' => $billing];
        }
        $processing = $overview['processing'];
        if ($overview['status'] === 'active' && $processing['total']) {
            if ($processing['exceeded'] > 0) {
                $items[] = ['kind' => 'processing_over', 'tone' => 'red', 'data' => ['seconds' => $processing['exceeded']], 'url' => $billing];
            } elseif ($processing['available'] !== null && $processing['available'] < $processing['total'] * 0.1) {
                $items[] = ['kind' => 'processing_low', 'tone' => 'amber', 'data' => ['seconds' => $processing['available']], 'url' => $billing];
            }
        }
        $storage = $overview['storage'];
        if ($storage['total'] && $storage['used'] + $storage['reserved'] >= $storage['total'] * 0.9) {
            $items[] = ['kind' => 'storage_full', 'tone' => 'amber', 'data' => ['percent' => min(100, (int) floor(($storage['used'] + $storage['reserved']) / $storage['total'] * 100))], 'url' => route('projects.index', absolute: false)];
        }

        return array_map(fn (array $item): array => [...$item, 'key' => $item['kind'].':'.($item['key'] ?? md5(json_encode($item['data'])))], $items);
    }

    /**
     * Projects that are running, waiting for the customer, failed recently or not started.
     *
     * @return array<string, mixed>
     */
    public function work(User $user): array
    {
        $statuses = [...ProjectLibrary::GROUPS['processing'], 'awaiting_review', ...self::FAILED, ...self::DRAFT];
        $projects = MediaProject::where('user_id', $user->id)->whereIn('status', $statuses)
            ->where(fn (Builder $q) => $q->whereNotIn('status', self::FAILED)->orWhere('updated_at', '>=', now()->subDays(14)))
            ->with('activeRun')->orderByDesc('updated_at')->limit(60)->get();
        $rank = fn (MediaProject $project): int => match ($this->state($project)) {
            'running' => 0, 'queued', 'retrying' => 1, 'review' => 2, 'failed' => 3, default => 4,
        };
        $items = $projects->sortBy(fn (MediaProject $project) => [$rank($project), -$project->updated_at->getTimestamp()])->values();
        $runs = MediaRun::whereHas('project', fn (Builder $q) => $q->where('user_id', $user->id))
            ->whereIn('status', [...self::RUNNING, 'queued', 'retry_waiting'])->get(['status', 'phase']);

        return [
            'total' => $items->count(),
            'items' => $items->take(4)->map(fn (MediaProject $project) => $this->workItem($project))->all(),
            'progress' => [
                'total' => $runs->count(),
                'rendering' => $runs->where('status', 'rendering')->count(),
                'writing' => $runs->whereIn('status', ['analyzing', 'translating'])->count(),
                'queued' => $runs->whereIn('status', ['queued', 'retry_waiting'])->count(),
                'stopping' => $runs->where('status', 'cancel_requested')->count(),
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    public function recent(User $user): array
    {
        return MediaProject::where('user_id', $user->id)->where('status', 'completed')
            ->with(['runs' => fn ($runs) => $runs->where('phase', 'generate')->where('status', 'completed')->orderByDesc('finished_at')->limit(1)])
            ->orderByDesc('updated_at')->limit(6)->get()
            ->map(function (MediaProject $project): array {
                $link = fn (string $kind) => route('media.file', [$project->id, $kind], false);

                return [...$this->identity($project),
                    'duration' => (float) ($project->metadata['output']['duration'] ?? $project->metadata['duration'] ?? 0),
                    'completed_at' => ($project->runs->first()?->finished_at ?? $project->updated_at)->toIso8601String(),
                    'url' => route('media.analysis', $project->id, false).'?studio=render',
                    'video' => $project->output_path ? $link('video').'?download=1' : null,
                    'subtitles' => $project->subtitle_path ? $link('subtitles') : null,
                    'audio' => $project->audio_path ? $link('audio').'?download=1' : null,
                    'audio_format' => $project->audio_path ? strtoupper(pathinfo($project->audio_path, PATHINFO_EXTENSION)) : null,
                ];
            })->all();
    }

    /**
     * Getting-started steps; hidden after the first export or when dismissed.
     *
     * @return array<string, mixed>|null
     */
    public function checklist(User $user): ?array
    {
        $runs = MediaRun::whereHas('project', fn (Builder $q) => $q->where('user_id', $user->id));
        if ($user->dashboard_checklist_hidden_at || (clone $runs)->where('phase', 'generate')->where('status', 'completed')->exists()) {
            return null;
        }
        $projects = MediaProject::where('user_id', $user->id);
        $fresh = User::query()->whereKey($user->id)->first(['id', 'studio_preferences', 'default_preset_id']);

        return ['steps' => [
            'account' => true,
            'upload' => (clone $projects)->whereNotIn('status', ['uploading', 'importing', 'import_failed', 'import_cancelled', 'invalid'])->exists(),
            'review' => (clone $runs)->where(fn (Builder $q) => $q->where('phase', 'generate')->orWhere('status', 'awaiting_review'))->exists(),
            'export' => false,
            'defaults' => ($fresh?->studio_preferences ?? []) !== [] || (bool) $fresh?->default_preset_id || MediaPreset::where('user_id', $user->id)->exists(),
        ]];
    }

    /** @return array<string, mixed> */
    public function billingSnapshot(User $user): array
    {
        $last = PaymentTransaction::where('user_id', $user->id)->where('status', 'approved')->orderByDesc('approved_at')->orderByDesc('id')->first();
        $pending = $this->billing->pending($user);
        $row = fn (?PaymentTransaction $payment) => $payment ? ['id' => $payment->id, 'code' => $this->code($payment->id), 'status' => app(Payments::class)->status($payment),
            'currency' => $payment->currency, 'amount_minor' => $payment->status === 'approved' && $payment->received_minor ? $payment->received_minor : $payment->expected_minor,
            'at' => ($payment->approved_at ?? $payment->submitted_at ?? $payment->created_at)->toIso8601String()] : null;

        return ['last' => $row($last), 'pending' => $row($pending), 'any' => $last !== null || $pending !== null || PaymentTransaction::where('user_id', $user->id)->exists()];
    }

    /**
     * Processing charged for successful exports, by day (14/30) or month (all time),
     * in the customer's display time zone.
     *
     * @return array<string, mixed>
     */
    public function usage(User $user, string $range): array
    {
        $timezone = $this->profile->applyDisplay($this->settings->publicData(), $user)['timezone'] ?? config('app.timezone');
        $now = CarbonImmutable::now($timezone);
        $monthly = $range === 'all';
        $start = $monthly ? null : $now->startOfDay()->subDays((int) $range - 1);
        $at = 'COALESCE(media_runs.finished_at, usage_records.updated_at)';
        $rows = UsageRecord::query()->leftJoin('media_runs', 'media_runs.id', '=', 'usage_records.media_run_id')
            ->where('usage_records.user_id', $user->id)->where('usage_records.kind', 'processing')->whereIn('usage_records.status', ['charged', 'adjusted'])
            ->when($start, fn (Builder $q) => $q->whereRaw($at.' >= ?', [$start->utc()->toDateTimeString()]))
            ->selectRaw('usage_records.charged_seconds - usage_records.returned_seconds AS seconds, usage_records.charged_videos - usage_records.returned_videos AS videos, '.$at.' AS happened_at')
            ->get();
        $key = $monthly ? 'Y-m' : 'Y-m-d';
        $grouped = $rows->filter(fn ($row) => (int) $row->seconds > 0 || (int) $row->videos > 0)
            ->groupBy(fn ($row) => CarbonImmutable::parse($row->happened_at, 'UTC')->setTimezone($timezone)->format($key));
        $buckets = new Collection;
        if ($monthly) {
            $first = $grouped->keys()->sort()->first();
            $cursor = $first ? CarbonImmutable::createFromFormat('!Y-m', $first, $timezone) : $now->startOfMonth();
            $cursor = $cursor->max($now->startOfMonth()->subMonths(23))->min($now->startOfMonth()->subMonths(5));
            for (; $cursor <= $now; $cursor = $cursor->addMonth()) {
                $buckets->push($cursor);
            }
        } else {
            for ($day = $start; $day <= $now; $day = $day->addDay()) {
                $buckets->push($day);
            }
        }
        $bars = $buckets->map(function (CarbonImmutable $date) use ($grouped, $key): array {
            $items = $grouped->get($date->format($key), collect());

            return ['date' => $date->format($key), 'seconds' => (int) $items->sum('seconds'), 'videos' => (int) $items->sum('videos')];
        })->values();

        return ['range' => $range, 'unit' => $monthly ? 'month' : 'day', 'bars' => $bars->all(),
            'total_seconds' => (int) $bars->sum('seconds'), 'videos' => (int) $bars->sum('videos')];
    }

    public function hideChecklist(User $user): void
    {
        $user->forceFill(['dashboard_checklist_hidden_at' => now()])->save();
    }

    /** @return array<string, mixed> */
    private function workItem(MediaProject $project): array
    {
        $run = $project->activeRun;
        $state = $this->state($project);
        $running = in_array($state, ['running', 'queued', 'retrying'], true);

        return [...$this->identity($project),
            'state' => $state,
            'stage' => match (true) {
                $project->status === 'cancel_requested' => 'stopping',
                $project->status === 'rendering' => 'rendering',
                in_array($project->status, ['analyzing', 'translating'], true) => 'writing',
                $project->status === 'importing' => 'importing',
                $project->status === 'uploading' => 'uploading',
                $project->status === 'validating' => 'validating',
                default => null,
            },
            'progress' => match (true) {
                $state === 'running' && $run !== null => max(0, min(100, (int) $run->progress)),
                in_array($project->status, ['importing', 'validating'], true) => max(0, min(100, (int) $project->source_progress)),
                default => null,
            },
            'duration' => (float) ($project->metadata['duration'] ?? 0),
            'error' => $state === 'failed' ? ($run?->error_code ?? $project->error_code) : null,
            'url' => $this->destination($project),
            'cancel' => $running && $project->status !== 'cancel_requested' ? route('media.cancel', $project->id, false) : null,
            'retry' => $project->status === 'failed' && $run ? route('media.retry', $project->id, false) : ($project->status === 'import_failed' ? route('media.import.retry', $project->id, false) : null),
            'updated_at' => $project->updated_at->toIso8601String(),
        ];
    }

    private function state(MediaProject $project): string
    {
        return match (true) {
            in_array($project->status, self::RUNNING, true) => 'running',
            $project->status === 'queued' => $project->activeRun?->status === 'retry_waiting' ? 'retrying' : 'queued',
            $project->status === 'awaiting_review' => 'review',
            in_array($project->status, self::FAILED, true) => 'failed',
            in_array($project->status, ['importing', 'validating', 'uploading'], true) => 'preparing',
            default => 'draft',
        };
    }

    /** @return array<string, mixed> */
    private function identity(MediaProject $project): array
    {
        $settings = $project->settings ?? [];

        return ['id' => $project->id, 'title' => $project->title,
            'type' => ($settings['mode'] ?? 'subs') === 'subs' ? 'subtitles' : (($settings['workflow'] ?? 'movie') === 'translate' ? 'translate' : 'movie'),
            'poster' => ($project->metadata['poster'] ?? false) ? route('media.file', [$project->id, 'poster'], false) : null,
            'source' => $project->source_type === 'youtube' ? 'youtube' : 'upload'];
    }

    private function destination(MediaProject $project): string
    {
        $link = fn (string $name) => route($name, $project->id, false);
        if ($project->source_type === 'youtube' && in_array($project->status, ['importing', 'import_failed', 'import_cancelled'], true)) {
            return $link('media.upload.page');
        }

        return match (true) {
            in_array($project->status, ['uploading', 'validating'], true) => $link('media.upload.page'),
            $project->status === 'ready' => $link('media.setup'),
            $project->status === 'completed' => $link('media.analysis').'?studio=render',
            $project->active_run_id !== null || ($project->lines ?? []) !== [] => $link('media.analysis'),
            default => ($project->metadata['audio'] ?? false) ? $link('media.setup') : $link('media.upload.page'),
        };
    }

    private function code(int $id): string
    {
        return 'PYT-'.str_pad((string) $id, 7, '0', STR_PAD_LEFT);
    }
}
