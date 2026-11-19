<?php

namespace App\Services;

use App\Models\CustomerPackageAssignment;
use App\Models\MediaProject;
use App\Models\MediaTask;
use App\Models\PaymentRefund;
use App\Models\PaymentTransaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Customer-facing notifications created from real events: finished or failed
 * videos, payment decisions, refunds, plan changes and account security.
 * Admin notes, internal error codes and support replies are never included.
 */
class CustomerNotifications
{
    public const CATEGORIES = ['video', 'payment', 'plan', 'account'];

    public const PAGE_SIZE = 10;

    /** @var array<string, string> */
    private const TYPES = [
        'video_ready' => 'video', 'script_ready' => 'video', 'export_failed' => 'video', 'analysis_failed' => 'video',
        'payment_approved' => 'payment', 'payment_info_needed' => 'payment', 'payment_rejected' => 'payment', 'refund_completed' => 'payment',
        'plan_expiring' => 'plan', 'plan_expired' => 'plan', 'plan_started' => 'plan',
        'password_changed' => 'account', 'email_changed' => 'account',
    ];

    /**
     * Records one notification; the same dedupe key is stored only once per customer.
     *
     * @param  array<string, mixed>  $data
     */
    public function notify(int $userId, string $type, array $data, string $dedupe): void
    {
        $category = self::TYPES[$type] ?? null;
        if (! $category || ! User::whereKey($userId)->where('is_admin', false)->exists()) {
            return;
        }
        try {
            DB::table('customer_notifications')->insertOrIgnore([
                'user_id' => $userId, 'type' => $type, 'category' => $category, 'data' => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'dedupe_key' => mb_substr($dedupe, 0, 160), 'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (Throwable $error) {
            report($error);
        }
    }

    public function fromTask(MediaTask $task): void
    {
        $project = $task->project;
        if (! $project) {
            return;
        }
        $data = ['project' => mb_substr((string) $project->title, 0, 120), 'project_id' => $project->id];
        $match = match (true) {
            $task->type === 'generate' && $task->status === 'completed' => ['video_ready', 'ready'],
            $task->type === 'analysis' && $task->status === 'awaiting_review' => ['script_ready', 'review'],
            $task->type === 'generate' && $task->status === 'failed' => ['export_failed', 'failed'],
            $task->type === 'analysis' && $task->status === 'failed' => ['analysis_failed', 'failed'],
            default => null,
        };
        if ($match) {
            $this->notify($project->user_id, $match[0], $data, 'task:'.$task->id.':'.$match[1].':'.($task->attempts ?? 0));
        }
    }

    public function fromPayment(PaymentTransaction $payment): void
    {
        $type = ['approved' => 'payment_approved', 'rejected' => 'payment_rejected', 'needs_information' => 'payment_info_needed'][$payment->status] ?? null;
        if (! $type) {
            return;
        }
        $this->notify($payment->user_id, $type, [
            'payment_id' => $payment->id, 'code' => $this->paymentCode($payment->id), 'package' => (string) ($payment->package_snapshot['name'] ?? ''),
            'currency' => $payment->currency, 'amount_minor' => (int) ($payment->received_minor ?? $payment->expected_minor ?? 0),
        ], 'payment:'.$payment->id.':'.$payment->status.':'.$payment->revision);
    }

    public function fromRefund(PaymentRefund $refund): void
    {
        if ($refund->status !== 'completed') {
            return;
        }
        $payment = PaymentTransaction::find($refund->payment_transaction_id);
        if (! $payment) {
            return;
        }
        $this->notify($payment->user_id, 'refund_completed', [
            'payment_id' => $payment->id, 'code' => $this->paymentCode($payment->id), 'currency' => $payment->currency, 'amount_minor' => (int) $refund->amount_minor,
        ], 'refund:'.$refund->id);
    }

    /**
     * Plan notifications depend on time, so they are derived on demand (at most every few minutes).
     */
    public function syncPlan(User $user): void
    {
        Cache::remember('customer-notifications-plan:'.$user->id, now()->addMinutes(5), function () use ($user): bool {
            $current = CustomerPackageAssignment::current()->where('user_id', $user->id)->first();
            if ($current && $current->status() === 'active') {
                $name = (string) ($current->effectiveSnapshot()['name'] ?? '');
                $end = $current->ends_at?->toIso8601String();
                if (str_starts_with((string) $current->source, 'payment_') && $current->starts_at->greaterThan(now()->subDays(14))) {
                    $this->notify($user->id, 'plan_started', ['package' => $name, 'ends_at' => $end], 'plan:'.$current->id.':started');
                }
                $days = $current->ends_at ? (int) ceil(now()->diffInSeconds($current->ends_at, false) / 86400) : null;
                $next = CustomerPackageAssignment::standing()->where('user_id', $user->id)->whereNull('closed_kind')->where('starts_at', '>=', $current->ends_at ?? now())->exists();
                foreach ([1, 7] as $threshold) {
                    if ($days !== null && $days > 0 && $days <= $threshold && ! $next) {
                        $this->notify($user->id, 'plan_expiring', ['package' => $name, 'days' => $days, 'ends_at' => $end], 'plan:'.$current->id.':expiring:'.$threshold);
                        break;
                    }
                }
            } else {
                $last = $current && in_array($current->status(), ['expired', 'ended'], true) ? $current
                    : CustomerPackageAssignment::where('user_id', $user->id)->where('starts_at', '<=', now())->where('ends_at', '<=', now())->whereNull('closed_kind')->latest('ends_at')->first();
                $endedAt = $last?->closed_at ?? $last?->ends_at;
                if ($last && $endedAt && $endedAt->greaterThan(now()->subDays(30))) {
                    $this->notify($user->id, 'plan_expired', ['package' => (string) ($last->effectiveSnapshot()['name'] ?? ''), 'ends_at' => $endedAt->toIso8601String()], 'plan:'.$last->id.':expired');
                }
            }

            return true;
        });
    }

    /** @return array{unread: int, all: list<array<string, mixed>>, unread_items: list<array<string, mixed>>} */
    public function feed(User $user): array
    {
        $this->syncPlan($user);
        $base = $this->query($user)->whereNull('archived_at');

        return [
            'unread' => (clone $base)->whereNull('read_at')->count(),
            'all' => $this->present((clone $base)->orderByDesc('id')->limit(8)->get()),
            'unread_items' => $this->present((clone $base)->whereNull('read_at')->orderByDesc('id')->limit(8)->get()),
        ];
    }

    /**
     * @param  array{tab?: string, status?: string, period?: string, archived?: bool, q?: string, page?: int}  $filters
     * @return array<string, mixed>
     */
    public function listing(User $user, array $filters): array
    {
        $this->syncPlan($user);
        $archived = (bool) ($filters['archived'] ?? false);
        $scope = $this->query($user)->when(! $archived, fn (Builder $query) => $query->whereNull('archived_at'));
        $period = ['7' => 7, '30' => 30][$filters['period'] ?? ''] ?? null;
        if ($period) {
            $scope->where('created_at', '>=', now()->subDays($period));
        }
        $term = trim((string) ($filters['q'] ?? ''));
        if ($term !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
            $scope->where(fn (Builder $query) => $query->where('data', 'like', $like)->orWhere('type', 'like', $like));
        }
        $counts = ['all' => (clone $scope)->count(), 'unread' => (clone $scope)->whereNull('read_at')->count()];
        foreach (self::CATEGORIES as $category) {
            $counts[$category] = (clone $scope)->where('category', $category)->count();
        }
        $tab = $filters['tab'] ?? 'all';
        $rows = clone $scope;
        if ($tab === 'unread') {
            $rows->whereNull('read_at');
        } elseif (in_array($tab, self::CATEGORIES, true)) {
            $rows->where('category', $tab);
        }
        $status = $filters['status'] ?? '';
        if ($status === 'unread') {
            $rows->whereNull('read_at');
        } elseif ($status === 'read') {
            $rows->whereNotNull('read_at');
        }
        $total = (clone $rows)->count();
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min(max(1, (int) ($filters['page'] ?? 1)), $pages);

        return [
            'items' => $this->present($rows->orderByDesc('id')->offset(($page - 1) * self::PAGE_SIZE)->limit(self::PAGE_SIZE)->get()),
            'counts' => $counts, 'unread' => $this->query($user)->whereNull('archived_at')->whereNull('read_at')->count(),
            'pagination' => ['page' => $page, 'pages' => $pages, 'total' => $total, 'per_page' => self::PAGE_SIZE],
            'filters' => ['tab' => $tab, 'status' => $status, 'period' => $filters['period'] ?? '30', 'archived' => $archived, 'q' => $term],
        ];
    }

    /**
     * @param  list<int>  $ids
     */
    public function act(User $user, string $action, array $ids = []): int
    {
        $query = $this->query($user);
        if ($action !== 'read_all') {
            $query->whereIn('id', $ids);
        }

        return match ($action) {
            'read', 'read_all' => $query->whereNull('read_at')->update(['read_at' => now(), 'updated_at' => now()]),
            'unread' => $query->update(['read_at' => null, 'updated_at' => now()]),
            'archive' => $query->update(['archived_at' => now(), 'read_at' => DB::raw('COALESCE(read_at, CURRENT_TIMESTAMP)'), 'updated_at' => now()]),
            'unarchive' => $query->update(['archived_at' => null, 'updated_at' => now()]),
            default => 0,
        };
    }

    private function query(User $user): Builder
    {
        return DB::table('customer_notifications')->where('user_id', $user->id);
    }

    private function paymentCode(int $id): string
    {
        return 'PYT-'.str_pad((string) $id, 7, '0', STR_PAD_LEFT);
    }

    /**
     * @param  iterable<object>  $rows
     * @return list<array<string, mixed>>
     */
    private function present(iterable $rows): array
    {
        $rows = collect($rows);
        $projectIds = $rows->map(fn (object $row) => json_decode((string) $row->data, true)['project_id'] ?? null)->filter()->unique()->values();
        $projects = $projectIds->isEmpty() ? collect() : MediaProject::whereIn('id', $projectIds)->get(['id', 'output_path'])->keyBy('id');
        $items = [];
        foreach ($rows as $row) {
            $data = json_decode((string) $row->data, true) ?: [];
            $project = isset($data['project_id']) ? $projects->get($data['project_id']) : null;
            $gone = isset($data['project_id']) && ! $project;
            $url = $gone ? null : $this->url($row->type, $data);
            $action = $gone ? null : match ($row->type) {
                'video_ready' => $project?->output_path ? ['kind' => 'download', 'url' => route('media.file', ['mediaProject' => $project->id, 'kind' => 'video', 'download' => 1], absolute: false)] : null,
                'script_ready' => ['kind' => 'review', 'url' => $url],
                'export_failed', 'analysis_failed' => ['kind' => 'retry', 'url' => $url],
                'payment_info_needed' => ['kind' => 'provide', 'url' => $url],
                'plan_expiring', 'plan_expired' => ['kind' => 'renew', 'url' => route('account.billing', absolute: false)],
                default => null,
            };
            $items[] = ['id' => (int) $row->id, 'type' => $row->type, 'category' => $row->category, 'data' => array_diff_key($data, ['project_id' => true, 'payment_id' => true]),
                'read' => $row->read_at !== null, 'archived' => $row->archived_at !== null, 'removed' => $gone,
                'at' => CarbonImmutable::parse($row->created_at, 'UTC')->toIso8601String(), 'url' => $url, 'action' => $action];
        }

        return $items;
    }

    /** @param  array<string, mixed>  $data */
    private function url(string $type, array $data): ?string
    {
        return match (true) {
            isset($data['project_id']) => route('media.analysis', ['mediaProject' => $data['project_id']], absolute: false),
            isset($data['payment_id']) => route('account.billing', ['payment' => $data['payment_id']], absolute: false),
            $type === 'plan_expired' => route('account.billing', absolute: false),
            str_starts_with($type, 'plan_') => route('account.plan', absolute: false),
            in_array($type, ['password_changed', 'email_changed'], true) => route('settings.preferences', ['tab' => 'general'], absolute: false),
            default => null,
        };
    }
}
