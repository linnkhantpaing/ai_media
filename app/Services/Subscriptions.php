<?php

namespace App\Services;

use App\Models\CustomerAccountEvent;
use App\Models\CustomerPackageAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class Subscriptions
{
    public function visible(): Builder
    {
        return CustomerPackageAssignment::query()->standing()->whereHas('user', fn (Builder $query) => $query->where('is_admin', false))
            ->where(fn (Builder $query) => $query->whereNull('closed_kind')->orWhereIn('closed_kind', ['ended', 'cancelled']));
    }

    public function listing(string $status = ''): Builder
    {
        $now = now();
        $choice = $this->visible()->select('id')->whereColumn('user_id', 'subscription_customers.id');
        if ($status === 'upcoming') {
            $this->filterStatus($choice, 'upcoming');
            $choice->orderBy('starts_at')->orderByDesc('id');
        } else {
            $choice->orderByRaw('CASE WHEN closed_kind IS NULL AND starts_at <= ? AND ends_at > ? THEN 0 WHEN closed_kind IS NULL AND starts_at > ? THEN 1 WHEN closed_kind = ? THEN 3 ELSE 2 END', [$now, $now, $now, 'cancelled'])
                ->orderByRaw('CASE WHEN closed_kind IS NULL AND starts_at > ? THEN starts_at END ASC', [$now])
                ->orderByDesc('starts_at')->orderByDesc('id');
        }
        $ids = DB::table('users as subscription_customers')->selectSub($choice->limit(1), 'assignment_id');

        return $this->visible()->whereIn('customer_package_assignments.id', $ids);
    }

    public function filterStatus(Builder $query, string $status): void
    {
        match ($status) {
            'active' => $query->whereNull('closed_kind')->where('starts_at', '<=', now())->where('ends_at', '>', now()),
            'expiring' => $query->whereNull('closed_kind')->where('starts_at', '<=', now())->where('ends_at', '>', now())->where('ends_at', '<=', now()->addDays(7)),
            'upcoming' => $query->whereNull('closed_kind')->where('starts_at', '>', now()),
            'expired' => $query->whereNull('closed_kind')->where('ends_at', '<=', now()),
            'ended', 'cancelled' => $query->where('closed_kind', $status),
            default => null,
        };
    }

    public function find(int $id): CustomerPackageAssignment
    {
        return CustomerPackageAssignment::with('user')->whereHas('user', fn (Builder $query) => $query->where('is_admin', false))->findOrFail($id);
    }

    public function present(CustomerPackageAssignment $assignment): array
    {
        $user = $assignment->user;
        $status = $assignment->status();
        $snapshot = $assignment->effectiveSnapshot();
        $definition = $snapshot['definition'];
        $usage = app(UsageLedger::class)->balances($assignment->user_id, $assignment->allowance_starts_at ?? $assignment->starts_at);

        return [
            'id' => $assignment->id, 'code' => 'SUB-'.str_pad((string) $assignment->id, 6, '0', STR_PAD_LEFT),
            'customer' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'avatar_url' => CustomerProfile::adminAvatarUrl($user->id, $user->avatar_path), 'status' => $user->account_status],
            'revision' => $user->account_revision,
            'package' => ['id' => $assignment->package_id, 'name' => $snapshot['name'], 'code' => $snapshot['code'], 'version' => $snapshot['version'], 'type' => $snapshot['type'] ?? $definition['type'] ?? 'paid', 'definition' => array_diff_key($definition, ['note' => true])],
            'starts_at' => $assignment->starts_at->toIso8601String(), 'ends_at' => $assignment->ends_at->toIso8601String(),
            'starts_on' => $assignment->starts_at->setTimezone(config('app.timezone'))->toDateString(),
            'ends_on' => $assignment->ends_at->setTimezone(config('app.timezone'))->toDateString(),
            'status' => $status, 'expiring' => $status === 'active' && $assignment->ends_at->lessThanOrEqualTo(now()->addDays(7)),
            'days_left' => max(0, (int) ceil(now()->diffInDays($assignment->ends_at, false))),
            'starts_in' => max(0, (int) ceil(now()->diffInDays($assignment->starts_at, false))),
            'source' => $assignment->source, 'closed_at' => $assignment->closed_at?->toIso8601String(),
            'allowance_starts_at' => ($assignment->allowance_starts_at ?? $assignment->starts_at)->toIso8601String(),
            'usage' => ['videos' => $usage['videos'], 'processing_minutes' => $usage['seconds'] / 60, 'reserved_seconds' => $usage['reserved_seconds']],
            'actions' => $user->account_status === 'archived' ? [] : match ($status) {
                'active' => ['end'], 'upcoming' => ['cancel'], default => [],
            },
            'show_url' => route('admin.subscriptions.show', $assignment->id, absolute: false),
            'action_url' => route('admin.subscriptions.action', $assignment->id, absolute: false),
        ];
    }

    public function details(CustomerPackageAssignment $assignment): array
    {
        $next = CustomerPackageAssignment::standing()->with('user')->where('user_id', $assignment->user_id)->whereNull('closed_kind')->where('starts_at', '>', now())->where('id', '!=', $assignment->id)->orderBy('starts_at')->first();
        $storageBytes = (int) DB::table('media_files')->join('media_projects', 'media_projects.id', '=', 'media_files.media_project_id')
            ->where('media_projects.user_id', $assignment->user_id)->where('media_files.state', '!=', 'deleted')->sum('media_files.bytes');
        $history = CustomerAccountEvent::where('user_id', $assignment->user_id)->with('actor:id,name')
            ->where(fn (Builder $query) => $query->where('type', 'like', 'subscription_%')->orWhere('type', 'created')->orWhere('type', 'edit'))
            ->latest('id')->limit(100)->get()->map(fn (CustomerAccountEvent $event): array => [
                'id' => $event->id, 'type' => $event->type, 'actor' => $event->actor?->name,
                'metadata' => $event->metadata, 'created_at' => $event->created_at->toIso8601String(),
            ])->all();

        return [
            'subscription' => $this->present($assignment), 'next' => $next ? $this->present($next) : null,
            'history' => $history, 'storage_bytes' => $storageBytes,
            'user_url' => route('admin.users.index', ['q' => $assignment->user->email], absolute: false),
            'payments_url' => route('admin.payments.index', ['q' => $assignment->user->email], absolute: false),
            'periods' => CustomerPackageAssignment::where('user_id', $assignment->user_id)->with('user')->latest('id')->limit(50)->get()->map(fn ($period) => $this->present($period))->all(),
        ];
    }

    /**
     * Close a customer's access period. Only "end" (current period) and "cancel"
     * (scheduled period) are supported; new paid periods come from approved Payments.
     *
     * @param  array{action: string, request_id: string, revision: int, reason: string, note?: string|null}  $data
     * @return array{replayed: bool, id?: int}
     */
    public function mutate(User $actor, int $id, array $data): array
    {
        $target = $this->find($id);
        $fingerprint = hash_hmac('sha256', json_encode([$actor->id, $id, $data], JSON_THROW_ON_ERROR), (string) config('app.key'));

        return Cache::lock('customer:'.$target->user_id, 15)->block(5, fn (): array => DB::transaction(function () use ($actor, $id, $data, $target, $fingerprint): array {
            $user = User::whereKey($target->user_id)->lockForUpdate()->firstOrFail();
            $prior = CustomerAccountEvent::where('operation_key', $data['request_id'])->first();
            if ($prior) {
                abort_unless($prior->user_id === $user->id && hash_equals((string) $prior->fingerprint, $fingerprint), 409, 'This request has already been used.');

                return ['replayed' => true];
            }
            abort_unless($user->account_status !== 'archived', 409, 'Restore this account before changing its subscription.');
            abort_unless($user->account_revision === (int) $data['revision'], 409, 'This subscription changed. Refresh and review before saving.');
            $assignment = CustomerPackageAssignment::whereKey($id)->standing()->lockForUpdate()->firstOrFail();
            $before = $this->audit($assignment);
            $action = $data['action'];
            $allowed = match ($action) {
                'end' => $assignment->status() === 'active',
                'cancel' => $assignment->status() === 'upcoming',
                default => false,
            };
            abort_unless($allowed, 409, 'This action is no longer available. Refresh and review the subscription.');
            $this->close($assignment, $action === 'cancel' ? 'cancelled' : 'ended');
            $user->increment('account_revision');
            CustomerAccountEvent::create([
                'user_id' => $user->id, 'actor_id' => $actor->id, 'type' => 'subscription_'.$action,
                'metadata' => ['before' => $before, 'after' => $this->audit($assignment), 'reason' => $data['reason'], 'note' => $data['note'] ?? null],
                'operation_key' => $data['request_id'], 'fingerprint' => $fingerprint,
            ]);

            return ['replayed' => false, 'id' => $assignment->id];
        }));
    }

    private function close(CustomerPackageAssignment $assignment, string $kind): void
    {
        $assignment->update(['closed_kind' => $kind, 'closed_at' => now()]);
    }

    private function audit(CustomerPackageAssignment $assignment): array
    {
        return ['id' => $assignment->id, 'package' => $assignment->snapshot['name'], 'version' => $assignment->snapshot['version'], 'starts_at' => $assignment->starts_at->toIso8601String(), 'ends_at' => $assignment->ends_at->toIso8601String(), 'status' => $assignment->status()];
    }
}
