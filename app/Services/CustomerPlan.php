<?php

namespace App\Services;

use App\Models\CustomerPackageAssignment;
use App\Models\MediaRun;
use App\Models\PackageVersion;
use App\Models\PaymentTransaction;
use App\Models\UsageRecord;
use App\Models\User;
use App\Services\Media\StorageSettings;
use App\Support\StudioTesting;
use Illuminate\Database\Eloquent\Builder;

class CustomerPlan
{
    public function __construct(private UsageLedger $ledger) {}

    public function overview(User $user): array
    {
        $assignment = CustomerPackageAssignment::current()->where('user_id', $user->id)->first();
        $snapshot = $assignment?->effectiveSnapshot();
        $definition = $snapshot['definition'] ?? [];
        $balance = $this->ledger->balances($user->id, $assignment?->allowance_starts_at ?? $assignment?->starts_at);
        $used = $this->ledger->storageBytes($user->id);
        $allocated = $this->ledger->allocatedStorage($user->id);
        $state = $assignment?->status() ?? 'none';
        $active = $state === 'active' && $user->account_status === 'active';
        $seconds = $definition['included_seconds'] ?? (isset($definition['included_minutes']) ? (int) ($definition['included_minutes'] * 60) : null);
        $storage = $definition['storage_bytes'] ?? null;
        $next = CustomerPackageAssignment::standing()->where('user_id', $user->id)->whereNull('closed_kind')
            ->where('starts_at', '>', now())->when($assignment, fn (Builder $q) => $q->where('id', '!=', $assignment->id))->orderBy('starts_at')->first();
        $running = MediaRun::whereHas('project', fn (Builder $q) => $q->where('user_id', $user->id))
            ->whereIn('status', ['analyzing', 'translating', 'rendering', 'cancel_requested'])->count();
        $queued = MediaRun::whereHas('project', fn (Builder $q) => $q->where('user_id', $user->id))->whereIn('status', ['queued', 'retry_waiting'])->count();
        $policy = app(StorageSettings::class)->policy();

        return [
            'package' => $snapshot ? ['id' => $assignment->package_id, ...$this->publicPackage($snapshot)] : null,
            'status' => $user->account_status !== 'active' ? 'suspended' : $state,
            'expiring' => $active && $assignment->ends_at->lessThanOrEqualTo(now()->addDays(7)),
            'start' => $assignment?->starts_at->toIso8601String(), 'end' => $assignment?->ends_at->toIso8601String(),
            'days_left' => $active ? max(0, (int) ceil(now()->diffInDays($assignment->ends_at, false))) : 0,
            'next' => $next ? ['package' => $this->publicPackage($next->effectiveSnapshot()), 'start' => $next->starts_at->toIso8601String(), 'end' => $next->ends_at->toIso8601String()] : null,
            'pending_payment' => PaymentTransaction::where('user_id', $user->id)->whereIn('status', ['awaiting_payment', 'pending_review', 'under_review', 'needs_information'])->exists(),
            'processing' => $this->metric($seconds, $balance['seconds'], $balance['reserved_seconds'], $active),
            'videos' => $this->metric($definition['video_limit'] ?? null, $balance['videos'], $balance['reserved_videos'], $active),
            'storage' => $this->metric($storage, $used, max(0, $allocated - $used), true),
            'jobs' => ['running' => $running, 'queued' => $queued, 'concurrency' => $definition['concurrency'] ?? null, 'queue_limit' => $definition['queue_limit'] ?? null],
            'limits' => ['duration' => $definition['max_video_seconds'] ?? null, 'upload' => $definition['max_upload_bytes'] ?? (isset($definition['max_upload_mib']) ? $definition['max_upload_mib'] * 1048576 : null),
                'retention_days' => $definition['retention_days'] ?? null, 'cleanup_enabled' => (bool) ($policy['retention_enabled'] ?? false)],
            'features' => PackageCatalog::features($definition),
            'testing' => StudioTesting::enabled(), 'updated_at' => now()->toIso8601String(),
        ];
    }

    private function metric(?int $total, int $used, int $reserved, bool $available): array
    {
        return ['total' => $total, 'used' => $used, 'reserved' => $reserved,
            'available' => ! $available ? 0 : ($total === null ? null : max(0, $total - $used - $reserved)),
            'exceeded' => $total !== null ? max(0, $used + $reserved - $total) : 0];
    }

    private function publicPackage(array $snapshot): array
    {
        return array_intersect_key($snapshot, array_flip(['name', 'code', 'type', 'version']));
    }

    public function exports(User $user, array $filters): Builder
    {
        $query = MediaRun::query()->where('phase', 'generate')->where('status', 'completed')
            ->whereHas('project', fn (Builder $q) => $q->where('user_id', $user->id)
                ->where(fn (Builder $q) => $q->whereNotNull('output_path')->orWhereNotNull('audio_path')->orWhereNotNull('subtitle_path')))
            ->whereNotExists(function ($q): void {
                $q->selectRaw('1')->from('media_runs as newer')->whereColumn('newer.media_project_id', 'media_runs.media_project_id')
                    ->where('newer.phase', 'generate')->where('newer.status', 'completed')
                    ->whereRaw('(COALESCE(newer.finished_at, newer.updated_at) > COALESCE(media_runs.finished_at, media_runs.updated_at) OR (COALESCE(newer.finished_at, newer.updated_at) = COALESCE(media_runs.finished_at, media_runs.updated_at) AND newer.id > media_runs.id))');
            });
        if (($filters['q'] ?? '') !== '') {
            $query->whereHas('project', fn (Builder $q) => $q->whereRaw("title LIKE ? ESCAPE '!'", ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['q']).'%']));
        }
        $kind = $filters['type'] ?? '';
        if ($kind !== '') {
            $query->where('settings->mode', $kind === 'subs' ? 'subs' : 'dub');
            if ($kind !== 'subs') {
                $query->where('settings->workflow', $kind);
            }
        }
        if (($filters['period'] ?? 'current') === 'current') {
            $assignment = CustomerPackageAssignment::current()->where('user_id', $user->id)->first();
            if ($assignment) {
                $query->whereRaw('COALESCE(finished_at, updated_at) >= ?', [$assignment->allowance_starts_at ?? $assignment->starts_at])
                    ->whereRaw('COALESCE(finished_at, updated_at) < ?', [$assignment->ends_at]);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        return $query->with('project')->orderByRaw('COALESCE(finished_at, updated_at) DESC')->orderByDesc('id');
    }

    public function export(MediaRun $run, ?UsageRecord $record): array
    {
        $project = $run->project;
        $outputs = [];
        if ($project->output_path) {
            $outputs[] = 'MP4';
        }
        if ($project->subtitle_path) {
            $outputs[] = 'SRT';
        }
        if ($project->subtitle_path && ($project->metadata['output']['styled'] ?? false)) {
            $outputs[] = 'ASS';
        }
        if ($project->audio_path) {
            $outputs[] = strtoupper(pathinfo($project->audio_path, PATHINFO_EXTENSION));
        }

        return ['id' => $run->id, 'title' => $project->title,
            'type' => ($run->settings['mode'] ?? 'subs') === 'subs' ? 'subs' : (($run->settings['workflow'] ?? '') === 'movie' ? 'movie' : 'translate'),
            'poster' => ($project->metadata['poster'] ?? false) ? route('media.file', [$project->id, 'poster'], false) : null,
            'completed_at' => ($run->finished_at ?? $run->updated_at)->toIso8601String(),
            'duration' => max(0, (float) ($project->metadata['output']['duration'] ?? $project->metadata['duration'] ?? 0)),
            'charged_seconds' => $record ? max(0, $record->charged_seconds - $record->returned_seconds) : null,
            'counted_videos' => $record ? max(0, $record->charged_videos - $record->returned_videos) : null,
            'outputs' => $outputs, 'url' => route('media.analysis', $project->id, false).'?studio=render',
            'detail_url' => route('account.plan.export', $run->id, false)];
    }

    public function plans(): array
    {
        return PackageVersion::query()->join('packages', 'packages.id', '=', 'package_versions.package_id')
            ->where('packages.status', 'active')->whereColumn('package_versions.number', 'packages.published_version')
            ->orderBy('packages.id')->get(['package_versions.*', 'packages.name as package_name'])->map(fn ($version) => [
                'id' => $version->package_id, 'name' => $version->definition['name'] ?? $version->package_name,
                'type' => $version->definition['type'] ?? 'paid',
                'currency' => $version->definition['currency'] ?? '', 'price' => $version->definition['price'] ?? '0',
                'days' => $version->definition['validity_days'] ?? 0,
                'seconds' => $version->definition['included_seconds'] ?? (($version->definition['included_minutes'] ?? 0) * 60),
                'storage' => $version->definition['storage_bytes'] ?? 0, 'videos' => $version->definition['video_limit'] ?? null,
            ])->all();
    }
}
