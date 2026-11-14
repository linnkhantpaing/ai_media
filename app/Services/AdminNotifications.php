<?php

namespace App\Services;

use App\Models\AdminNotice;
use App\Models\AuditEvent;
use App\Models\CustomerPackageAssignment;
use App\Models\MediaTask;
use App\Models\MediaWorker;
use App\Models\PaymentRefund;
use App\Models\PaymentTransaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AdminNotifications
{
    public function publish(string $key, array $values, mixed $when = null): void
    {
        DB::transaction(function () use ($key, $values, $when): void {
            $values = array_replace(['customer_id' => null, 'customer_name' => null, 'customer_email' => null, 'context' => [], 'issue' => 'attention', 'priority' => 'warning'], $values);
            $new = [...$values, 'context' => json_encode($values['context'], JSON_THROW_ON_ERROR), 'dedupe_key' => $key,
                'revision' => 1, 'received_at' => $when ?? now(), 'resolved_at' => $values['issue'] === 'resolved' ? now() : null, 'created_at' => now(), 'updated_at' => now()];
            if (DB::table('admin_notices')->insertOrIgnore($new)) {
                return;
            }
            $notice = AdminNotice::where('dedupe_key', $key)->lockForUpdate()->firstOrFail();
            $reopened = $values['issue'] === 'attention' && ($notice->issue !== 'attention' || $notice->kind !== $values['kind'] || ($notice->priority !== 'critical' && $values['priority'] === 'critical'));
            if ($reopened) {
                $notice->revision++;
                $notice->received_at = now();
            }
            $notice->fill($values);
            $notice->resolved_at = $values['issue'] === 'resolved' ? ($notice->resolved_at ?? now()) : null;
            if ($notice->isDirty()) {
                $notice->save();
            }
        });
    }

    private function customer(?int $id): array
    {
        $user = $id ? User::find($id, ['id', 'name', 'email']) : null;

        return ['customer_id' => $user?->id, 'customer_name' => $user ? mb_substr($user->name, 0, 120) : null, 'customer_email' => $user?->email];
    }

    private function resolve(string $key): void
    {
        AdminNotice::where('dedupe_key', $key)->whereIn('issue', ['attention', 'info'])->update(['issue' => 'resolved', 'resolved_at' => now(), 'updated_at' => now()]);
    }

    public function payment(PaymentTransaction $payment): void
    {
        $base = ['category' => 'payments', 'target_type' => 'payment', 'target_id' => (string) $payment->id,
            'reference' => 'PYT-'.str_pad((string) $payment->id, 7, '0', STR_PAD_LEFT), ...$this->customer($payment->user_id),
            'context' => ['package' => $payment->package_snapshot['name'] ?? '', 'currency' => $payment->currency, 'amount_minor' => $payment->received_minor ?? $payment->expected_minor, 'status' => $payment->status]];
        $key = 'payment-review:'.$payment->id;
        if (in_array($payment->status, ['pending_review', 'under_review'], true)) {
            $this->publish($key, [...$base, 'kind' => 'payment_review'], $payment->submitted_at ?? $payment->created_at);
        } else {
            $this->resolve($key);
        }
        if ($payment->status === 'approved' && ($payment->approved_at ?? $payment->updated_at)->gte(now()->subDays(7))) {
            $this->publish('payment-approved:'.$payment->id, [...$base, 'kind' => 'payment_approved', 'priority' => 'info', 'issue' => 'resolved'], $payment->approved_at ?? $payment->updated_at);
        }
    }

    public function refund(PaymentRefund $refund): void
    {
        $key = 'refund:'.$refund->id;
        if (! in_array($refund->status, ['requested', 'under_review'], true)) {
            $this->resolve($key);

            return;
        }
        $payment = PaymentTransaction::find($refund->payment_transaction_id);
        if (! $payment) {
            return;
        }
        $this->publish($key, ['category' => 'payments', 'kind' => 'refund_review', 'target_type' => 'refund', 'target_id' => (string) $refund->id,
            'reference' => 'RFD-'.str_pad((string) $refund->id, 7, '0', STR_PAD_LEFT), ...$this->customer($payment->user_id),
            'context' => ['payment_id' => $payment->id, 'currency' => $payment->currency, 'amount_minor' => $refund->amount_minor, 'status' => $refund->status]], $refund->created_at);
    }

    public function task(MediaTask $task): void
    {
        $key = 'job:'.$task->id;
        if (in_array($task->status, ['completed', 'awaiting_review', 'cancelled'], true)) {
            $this->resolve($key);

            return;
        }
        if (! in_array($task->status, ['failed', 'retry_waiting'], true)) {
            return;
        }
        $project = $task->project;
        $this->publish($key, ['category' => 'jobs', 'kind' => $task->status === 'failed' ? 'job_failed' : 'job_retry', 'priority' => $task->status === 'failed' ? 'critical' : 'warning',
            'target_type' => 'job', 'target_id' => (string) $task->id, 'reference' => 'J-'.str_pad((string) $task->id, 5, '0', STR_PAD_LEFT),
            ...$this->customer($project?->user_id), 'context' => ['project' => $project ? mb_substr($project->title, 0, 200) : '', 'status' => $task->status,
                'attempts' => $task->attempts, 'retry_at' => $task->next_retry_at?->toIso8601String()]], $task->finished_at ?? $task->updated_at);
    }

    public function subscription(CustomerPackageAssignment $assignment): void
    {
        $key = 'subscription:'.$assignment->id;
        $status = $assignment->status();
        $user = $assignment->user;
        if (! $user || $user->is_admin || $user->account_status === 'archived' || ! in_array($status, ['active', 'expired'], true) || $assignment->ends_at->lt(now()->subDays(30))) {
            $this->resolve($key);

            return;
        }
        if ($status === 'expired' && CustomerPackageAssignment::standing()->where('user_id', $assignment->user_id)->whereNull('closed_kind')->where('starts_at', '<=', now())->where('ends_at', '>', now())->exists()) {
            $this->resolve($key);

            return;
        }
        $snapshot = $assignment->effectiveSnapshot();
        $next = CustomerPackageAssignment::standing()->where('user_id', $assignment->user_id)->whereNull('closed_kind')->where('id', '!=', $assignment->id)
            ->where('starts_at', '>=', $assignment->ends_at)->orderBy('starts_at')->first();
        $context = ['package' => $snapshot['name'] ?? '', 'starts_at' => $assignment->starts_at->toIso8601String(), 'ends_at' => $assignment->ends_at->toIso8601String(),
            'scheduled_start' => $next?->starts_at->toIso8601String(), 'scheduled_package' => $next?->effectiveSnapshot()['name'] ?? null];
        $base = ['category' => 'subscriptions', 'target_type' => 'subscription', 'target_id' => (string) $assignment->id,
            'reference' => 'SUB-'.str_pad((string) $assignment->id, 6, '0', STR_PAD_LEFT), ...$this->customer($assignment->user_id), 'context' => $context];
        if ($assignment->ends_at->lte(now()->addDays(7))) {
            $covered = $next && $next->starts_at->lte($assignment->ends_at);
            $this->publish($key, [...$base, 'kind' => $status === 'expired' ? 'subscription_expired' : 'subscription_expiring',
                'issue' => $covered ? 'info' : 'attention', 'priority' => $covered ? 'info' : 'warning'], $status === 'expired' ? $assignment->ends_at : now());
        } else {
            $this->resolve($key);
        }
        if ($status === 'active' && $assignment->starts_at->gte(now()->subDay()) && in_array($assignment->source, ['payment', 'renewal', 'package_change', 'payment_new', 'payment_renewal', 'payment_change'], true)) {
            $this->publish('subscription-active:'.$assignment->id, [...$base, 'kind' => 'subscription_active', 'issue' => 'info', 'priority' => 'info'], $assignment->starts_at);
        }
        if ($status === 'active') {
            $quota = (int) ($snapshot['definition']['storage_bytes'] ?? 0);
            $used = app(UsageLedger::class)->storageBytes($user->id);
            if ($quota > 0 && $used >= $quota * .9) {
                $this->publish('storage:'.$user->id, [...$base, 'kind' => 'storage_alert', 'context' => ['used_bytes' => $used, 'quota_bytes' => $quota, 'package' => $snapshot['name'] ?? '']]);
            } else {
                $this->resolve('storage:'.$user->id);
            }
        }
    }

    public function audit(AuditEvent $event): void
    {
        if ($event->action === 'login_blocked') {
            $guard = $event->details['guard'] ?? 'web';
            $key = 'lockout:'.$guard.':'.hash('sha256', (string) $event->ip_address).':'.$event->occurred_at->toDateString();
            $this->publish($key, ['category' => 'system', 'kind' => 'security_blocked', 'priority' => 'warning', 'issue' => 'info',
                'target_type' => 'audit', 'target_id' => (string) $event->id, 'reference' => 'AUD-'.str_pad((string) $event->id, 7, '0', STR_PAD_LEFT),
                'context' => ['guard' => $guard]], $event->occurred_at);
        }
        if (in_array($event->source, ['provider_check', 'storage_check'], true)) {
            $target = $event->source === 'provider_check' ? 'providers' : 'storage';
            $row = DB::table($event->source === 'provider_check' ? 'media_provider_checks' : 'media_storage_checks')->find($event->source_id);
            $capability = $event->source === 'provider_check' ? ($row?->capability ?? 'connection') : 'connection';
            $key = 'connection:'.$target.':'.$capability;
            if ($event->result === 'success') {
                $this->resolve($key);
            } elseif ($event->result === 'failed') {
                $this->publish($key, ['category' => 'system', 'kind' => 'connection_failed', 'target_type' => $target, 'target_id' => null,
                    'reference' => $target === 'providers' ? 'API Keys & Models' : 'Storage & Retention', 'context' => ['capability' => $capability]], $event->occurred_at);
            }
        }
        if ($event->action === 'general.configuration_saved' && in_array('maintenance', $event->details['fields'] ?? [], true)) {
            $enabled = app(GeneralSettings::class)->current()['configuration']['maintenance']['enabled'];
            if ($enabled) {
                $this->publish('maintenance', ['category' => 'system', 'kind' => 'maintenance_enabled', 'priority' => 'info', 'issue' => 'info', 'target_type' => 'general', 'target_id' => null, 'reference' => 'General Settings', 'context' => []], $event->occurred_at);
            } else {
                AdminNotice::where('dedupe_key', 'maintenance')->update(['issue' => 'resolved', 'resolved_at' => now(), 'updated_at' => now()]);
            }
        }
        if (str_starts_with($event->action, 'general.smtp_')) {
            $key = str_contains($event->action, 'smtp_email_') ? 'connection:smtp:email' : 'connection:smtp:connection';
            if ($event->result === 'success') {
                $this->resolve($key);
                if (str_contains($event->action, 'smtp_email_')) {
                    $this->resolve('connection:smtp:connection');
                }
            } elseif ($event->result === 'failed') {
                $this->publish($key, ['category' => 'system', 'kind' => 'connection_failed', 'target_type' => 'general', 'target_id' => null,
                    'reference' => 'Outgoing Email (SMTP)', 'context' => []], $event->occurred_at);
            }
        }
    }

    public function synchronize(bool $force = false): void
    {
        Cache::lock('admin-notifications-sync', 60)->get(function () use ($force): void {
            if (! $force && ! Cache::add('admin-notifications-synced', true, 30)) {
                return;
            }
            try {
                PaymentTransaction::where(fn ($q) => $q->whereIn('status', ['pending_review', 'under_review'])
                    ->orWhere(fn ($q) => $q->where('status', 'approved')->where('approved_at', '>=', now()->subDays(7)))
                    ->orWhereIn('id', AdminNotice::where('dedupe_key', 'like', 'payment-review:%')->where('issue', 'attention')->select('target_id')))
                    ->orderBy('id')->chunkById(100, fn ($rows) => $rows->each(fn ($row) => $this->payment($row)));
                PaymentRefund::where(fn ($q) => $q->whereIn('status', ['requested', 'under_review'])
                    ->orWhereIn('id', AdminNotice::where('dedupe_key', 'like', 'refund:%')->where('issue', 'attention')->select('target_id')))
                    ->orderBy('id')->chunkById(100, fn ($rows) => $rows->each(fn ($row) => $this->refund($row)));
                MediaTask::where(fn ($q) => $q->where(fn ($q) => $q->whereIn('status', ['failed', 'retry_waiting'])->where('updated_at', '>=', now()->subDays(30)))
                    ->orWhereIn('id', AdminNotice::where('dedupe_key', 'like', 'job:%')->where('issue', 'attention')->select('target_id')))
                    ->with('project')->orderBy('id')->chunkById(100, fn ($rows) => $rows->each(fn ($row) => $this->task($row)));
                CustomerPackageAssignment::where(fn ($q) => $q->where(fn ($q) => $q->where('starts_at', '<=', now())->where('ends_at', '>=', now()->subDays(30)))
                    ->orWhereIn('id', AdminNotice::where('target_type', 'subscription')->where('issue', 'attention')->select('target_id')))
                    ->with('user')->orderBy('id')->chunkById(100, fn ($rows) => $rows->each(fn ($row) => $this->subscription($row)));
                AdminNotice::where('target_type', 'job')->where('issue', 'attention')->whereNotIn('target_id', MediaTask::select('id'))
                    ->update(['issue' => 'resolved', 'resolved_at' => now(), 'updated_at' => now()]);
                $activeCustomers = CustomerPackageAssignment::standing()->whereNull('closed_kind')->where('starts_at', '<=', now())->where('ends_at', '>', now())->whereHas('user', fn ($q) => $q->where('is_admin', false)->where('account_status', '!=', 'archived'))->select('user_id');
                AdminNotice::where('kind', 'storage_alert')->where('issue', 'attention')->whereNotIn('customer_id', $activeCustomers)->update(['issue' => 'resolved', 'resolved_at' => now(), 'updated_at' => now()]);
                $pending = DB::table('jobs')->whereIn('queue', config('operations.queues'))->exists();
                $ready = DB::table('jobs')->whereIn('queue', config('operations.queues'))->where('available_at', '<=', now()->subSeconds(90)->timestamp)->exists();
                $online = MediaWorker::whereNull('stopped_at')->where('heartbeat_at', '>=', now()->subSeconds(config('operations.heartbeat_ttl', 45)))->exists();
                $error = Cache::get('media-auto-worker-error');
                if (($ready && ! $online) || ($error && $pending)) {
                    $this->publish('worker:availability', ['category' => 'jobs', 'kind' => $error ? 'worker_start_failed' : 'worker_unavailable', 'priority' => 'critical',
                        'target_type' => 'workers', 'target_id' => null, 'reference' => 'Worker Health', 'context' => []]);
                } elseif ($online || ! $pending) {
                    $this->resolve('worker:availability');
                }
                foreach (DB::table('media_provider_checks')->selectRaw('MAX(id) AS id')->groupBy('capability')->get() as $check) {
                    $event = AuditEvent::where('source', 'provider_check')->where('source_id', $check->id)->first();
                    if ($event) {
                        $this->audit($event);
                    }
                }
                $checkId = DB::table('media_storage_checks')->max('id');
                if ($checkId && ($event = AuditEvent::where('source', 'storage_check')->where('source_id', $checkId)->first())) {
                    $this->audit($event);
                }
                $event = AuditEvent::where('source', 'general')->where('action', 'like', 'general.smtp_%')->latest('id')->first();
                if ($event) {
                    $this->audit($event);
                }
            } catch (\Throwable $error) {
                Cache::forget('admin-notifications-synced');
                throw $error;
            }
        });
    }

    public function inbox(User $admin, array $filters = []): Builder
    {
        abort_unless($admin->is_admin, 403);
        $query = DB::table('admin_notices as n')->leftJoin('admin_notice_states as s', fn ($join) => $join->on('s.notice_id', '=', 'n.id')->where('s.admin_id', $admin->id));
        if (($filters['archived'] ?? false)) {
            $query->whereColumn('s.archived_revision', 'n.revision');
        } else {
            $query->where(fn ($q) => $q->whereNull('s.archived_revision')->orWhereColumn('s.archived_revision', '!=', 'n.revision'));
        }
        if (($filters['read'] ?? '') === 'unread') {
            $query->where(fn ($q) => $q->whereNull('s.read_revision')->orWhereColumn('s.read_revision', '<', 'n.revision'));
        } elseif (($filters['read'] ?? '') === 'read') {
            $query->whereColumn('s.read_revision', '>=', 'n.revision');
        }
        foreach (['category', 'priority', 'issue'] as $key) {
            if ($filters[$key] ?? '') {
                $query->where('n.'.$key, $filters[$key]);
            }
        }
        $dates = $filters['dates'] ?? 'all';
        if ($dates === 'custom') {
            $query->where('n.received_at', '>=', $filters['from'].' 00:00:00')->where('n.received_at', '<', CarbonImmutable::parse($filters['to'], 'UTC')->addDay());
        } elseif ($dates !== 'all') {
            $query->where('n.received_at', '>=', now()->subDays((int) $dates));
        }
        if (trim($filters['q'] ?? '') !== '') {
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($filters['q'])).'%';
            $query->where(function ($q) use ($term, $filters): void {
                foreach (['customer_name', 'customer_email', 'reference', 'kind', 'target_id', 'context'] as $field) {
                    $q->orWhereRaw('n.'.$field." LIKE ? ESCAPE '!'", [$term]);
                }
                if (preg_match('/^(?:NTF-)?0*(\\d+)$/i', trim($filters['q']), $match)) {
                    $q->orWhere('n.id', (int) $match[1]);
                }
            });
        }

        return $query;
    }

    public function rows(Builder $query): Builder
    {
        return $query->select('n.*', 's.archived_revision')->selectRaw('CASE WHEN s.read_revision >= n.revision THEN 0 ELSE 1 END AS unread_flag');
    }

    public function summary(User $admin): array
    {
        $base = $this->inbox($admin);

        return ['unread' => (clone $base)->where(fn ($q) => $q->whereNull('s.read_revision')->orWhereColumn('s.read_revision', '<', 'n.revision'))->count(),
            'attention' => (clone $base)->where('n.issue', 'attention')->count(),
            'critical' => (clone $base)->where('n.issue', 'attention')->where('n.priority', 'critical')->count(),
            'today' => (clone $base)->where('n.received_at', '>=', now()->subDay())->count()];
    }

    public function change(User $admin, string $action, array $ids = []): void
    {
        abort_unless($admin->is_admin, 403);
        if ($action === 'read_all') {
            $maximum = (int) DB::table('admin_notices')->max('id');
            $this->rows($this->inbox($admin, ['read' => 'unread']))->where('n.id', '<=', $maximum)->orderBy('n.id')
                ->chunkById(250, fn ($rows) => $this->change($admin, 'read', $rows->pluck('id')->all()), 'n.id', 'id');

            return;
        }
        abort_unless(in_array($action, ['read', 'unread', 'archive', 'restore'], true), 422);
        DB::transaction(function () use ($admin, $action, $ids): void {
            $notices = AdminNotice::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            abort_unless(count($ids) === $notices->count(), 422);
            foreach ($notices as $notice) {
                $data = ['notice_id' => $notice->id, 'admin_id' => $admin->id, 'created_at' => now(), 'updated_at' => now()];
                $field = in_array($action, ['read', 'unread'], true) ? 'read_revision' : 'archived_revision';
                $data[$field] = match ($action) {
                    'unread' => 0, 'restore' => null, default => $notice->revision
                };
                DB::table('admin_notice_states')->upsert([$data], ['notice_id', 'admin_id'], [$field, 'updated_at']);
            }
        });
    }

    public function present(object $row): array
    {
        $context = is_string($row->context) ? json_decode($row->context, true) : $row->context;

        return ['id' => (int) $row->id, 'code' => 'NTF-'.str_pad((string) $row->id, 7, '0', STR_PAD_LEFT),
            'category' => $row->category, 'kind' => $row->kind, 'priority' => $row->priority, 'issue' => $row->issue,
            'unread' => (bool) $row->unread_flag, 'archived' => $row->archived_revision !== null && (int) $row->archived_revision === (int) $row->revision,
            'revision' => (int) $row->revision, 'customer' => ['name' => $row->customer_name, 'email' => $row->customer_email],
            'reference' => $row->reference, 'context' => $context,
            'received_at' => CarbonImmutable::parse($row->received_at)->toIso8601String(),
            'resolved_at' => $row->resolved_at ? CarbonImmutable::parse($row->resolved_at)->toIso8601String() : null,
            'detail_url' => route('admin.notifications.show', $row->id, absolute: false)];
    }

    public function detail(User $admin, int $id): array
    {
        $row = $this->rows($this->inbox($admin))->where('n.id', $id)->first()
            ?? $this->rows($this->inbox($admin, ['archived' => true]))->where('n.id', $id)->first();
        abort_unless($row, 404);
        $result = $this->present($row);
        $record = match ($row->target_type) {
            'payment' => PaymentTransaction::find($row->target_id), 'refund' => PaymentRefund::find($row->target_id),
            'job' => MediaTask::find($row->target_id), 'subscription' => CustomerPackageAssignment::find($row->target_id),
            'audit' => AuditEvent::find($row->target_id), default => null,
        };
        $url = match ($row->target_type) {
            'payment', 'refund' => $record ? route('admin.payments.index', ['q' => $row->target_type === 'refund' ? 'PYT-'.str_pad((string) $record->payment_transaction_id, 7, '0', STR_PAD_LEFT) : $row->reference], absolute: false) : null,
            'job' => $record ? route('admin.jobs.index', ['q' => $row->reference], absolute: false) : null,
            'subscription' => $record ? route('admin.subscriptions.index', ['q' => $row->reference], absolute: false) : null,
            'audit' => $record ? route('admin.audit.index', ['q' => $row->reference], absolute: false) : null,
            'support' => DB::table('support_conversations')->where('id', $row->target_id)->exists() ? route('admin.support.index', ['conversation' => $row->target_id], absolute: false) : null,
            'workers' => route('admin.jobs.workers', absolute: false), 'providers' => route('admin.settings.providers', absolute: false),
            'storage' => route('admin.settings.storage', absolute: false), 'general' => route('admin.settings.general', absolute: false), default => null,
        };
        $result['related_url'] = $url;
        $result['target_type'] = $row->target_type;
        $result['current'] = $record ? ['status' => $record instanceof CustomerPackageAssignment ? $record->status() : ($record->status ?? $record->result),
            'updated_at' => $record->updated_at?->toIso8601String()] : [];
        if ($record instanceof MediaTask) {
            $result['current'] += ['stage' => $record->stage, 'progress' => $record->progress, 'attempts' => $record->attempts];
        }
        if ($record instanceof CustomerPackageAssignment) {
            $result['current'] += ['starts_at' => $record->starts_at->toIso8601String(), 'ends_at' => $record->ends_at->toIso8601String(), 'package' => $record->effectiveSnapshot()['name'] ?? ''];
        }

        return $result;
    }
}
