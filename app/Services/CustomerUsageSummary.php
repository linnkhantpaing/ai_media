<?php

namespace App\Services;

use App\Models\CustomerPackageAssignment;
use App\Models\MediaProject;
use App\Models\UsageRecord;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class CustomerUsageSummary
{
    public function records(array $filters, bool $dates = true): Builder
    {
        $query = UsageRecord::query();
        $search = trim($filters['q'] ?? '');
        if ($search !== '') {
            $query->where(function (Builder $q) use ($search): void {
                $escaped = addcslashes($search, '%_\\');
                $q->where('customer_snapshot->name', 'like', '%'.$escaped.'%')
                    ->orWhere('customer_snapshot->email', 'like', '%'.$escaped.'%')
                    ->orWhere('project_snapshot->title', 'like', '%'.$escaped.'%')
                    ->orWhere('media_project_id', $search)->orWhere('media_run_id', $search);
                if (preg_match('/^(?:USG-)?0*([0-9]+)$/i', $search, $match)) {
                    $q->orWhere('id', (int) $match[1]);
                }
            });
        }
        if (($filters['period'] ?? 'current') === 'current') {
            $query->where(function (Builder $q): void {
                $q->whereExists(CustomerPackageAssignment::current()->selectRaw('1')
                    ->whereColumn('customer_package_assignments.user_id', 'usage_records.user_id')
                    ->whereRaw('COALESCE(customer_package_assignments.allowance_starts_at, customer_package_assignments.starts_at) = usage_records.allowance_start'))
                    ->orWhere(function (Builder $legacy): void {
                        $legacy->whereNull('allowance_start')->whereNotExists(CustomerPackageAssignment::current()->selectRaw('1')
                            ->whereColumn('customer_package_assignments.user_id', 'usage_records.user_id'));
                    });
            });
        }
        if (! empty($filters['package_id'])) {
            $query->where('package_id', $filters['package_id']);
        }
        if ($dates) {
            foreach (['kind', 'status'] as $field) {
                if (! empty($filters[$field])) {
                    $query->where($field, $filters[$field]);
                }
            }
            $range = $filters['dates'] ?? '30';
            $timezone = config('app.timezone', 'UTC');
            if ($range === 'custom') {
                $query->where('updated_at', '>=', CarbonImmutable::parse($filters['from'], $timezone)->startOfDay()->utc())
                    ->where('updated_at', '<', CarbonImmutable::parse($filters['to'], $timezone)->addDay()->startOfDay()->utc());
            } elseif ($range !== 'all') {
                $query->where('updated_at', '>=', now($timezone)->subDays((int) $range - 1)->startOfDay()->utc());
            }
        }

        return $query;
    }

    public function customers(array $filters): Builder
    {
        $users = User::where('is_admin', false)->where(fn (Builder $q) => $q->where('managed_account', true)
            ->orWhereHas('currentPackageAssignment')->orWhereExists(fn ($records) => $records->selectRaw('1')->from('usage_records')->whereColumn('usage_records.user_id', 'users.id')));
        $search = trim($filters['q'] ?? '');
        if ($search !== '') {
            $escaped = addcslashes($search, '%_\\');
            $users->where(fn (Builder $q) => $q->where('name', 'like', '%'.$escaped.'%')
                ->orWhere('email', 'like', '%'.$escaped.'%')
                ->orWhereIn('id', $this->records([...$filters, 'package_id' => ''])->select('user_id')));
        }
        if (! empty($filters['package_id'])) {
            $users->whereHas('currentPackageAssignment', fn (Builder $q) => $q->where('package_id', $filters['package_id']));
        }
        if (! empty($filters['kind']) || ! empty($filters['status'])) {
            $users->whereIn('id', $this->records([...$filters, 'package_id' => ''])->select('user_id'));
        }

        return $users;
    }

    public function summary(Builder $customers, array $filters): array
    {
        $ids = (clone $customers)->select('users.id');
        $selected = $this->records([...$filters, 'q' => '', 'package_id' => ''])->whereIn('user_id', clone $ids);
        $current = UsageRecord::whereIn('user_id', clone $ids);
        $totals = (clone $selected)->where('kind', 'processing')
            ->selectRaw('COALESCE(SUM(charged_seconds-returned_seconds),0) AS seconds, COALESCE(SUM(charged_videos-returned_videos),0) AS videos')->first();
        $storage = DB::table('media_files')->join('media_projects', 'media_projects.id', '=', 'media_files.media_project_id')
            ->whereIn('media_projects.user_id', clone $ids)->whereNotNull('media_files.verified_at')
            ->where('media_files.state', '!=', 'deleted')->sum('media_files.bytes');

        return ['seconds' => (int) $totals->seconds, 'videos' => (int) $totals->videos,
            'reserved_seconds' => (int) $current->where('kind', 'processing')->sum('reserved_seconds'), 'storage_bytes' => (int) $storage];
    }

    public function present(User $user, array $filters): array
    {
        $ledger = app(UsageLedger::class);
        $assignment = $user->currentPackageAssignment;
        $snapshot = $assignment?->effectiveSnapshot();
        $definition = $snapshot['definition'] ?? [];
        $anchor = $assignment?->allowance_starts_at ?? $assignment?->starts_at;
        $balances = $ledger->balances($user->id, $anchor);
        $usedStorage = $ledger->storageBytes($user->id);
        $allocatedStorage = $ledger->allocatedStorage($user->id);
        $seconds = $definition['included_seconds'] ?? (isset($definition['included_minutes']) ? (int) ($definition['included_minutes'] * 60) : null);
        $videos = $definition['video_limit'] ?? null;
        $storage = $definition['storage_bytes'] ?? null;
        $accessible = ! $assignment || ($assignment->status() === 'active' && $user->account_status === 'active');
        $recent = $this->recent($user->id, $filters);
        $count = (clone $recent)->count();
        $latest = $recent->orderByDesc('updated_at')->orderByDesc('id')->limit(3)->get()->map(fn (UsageRecord $record) => $this->video($record))->all();

        return [
            'id' => $user->id, 'customer' => ['name' => $user->name, 'email' => $user->email],
            'package' => $snapshot ? ['name' => $snapshot['name'], 'code' => $snapshot['code'], 'type' => $snapshot['type'],
                'version' => $snapshot['version']] : null,
            'period_start' => $assignment?->starts_at->toIso8601String(), 'period_end' => $assignment?->ends_at->toIso8601String(),
            'status' => $assignment?->status() ?? 'unmetered', 'account_status' => $user->account_status,
            'allowance' => ['seconds' => $seconds, 'videos' => $videos, 'storage_bytes' => $storage],
            'used' => ['seconds' => $balances['seconds'], 'videos' => $balances['videos'], 'storage_bytes' => $usedStorage],
            'reserved' => ['seconds' => $balances['reserved_seconds'], 'videos' => $balances['reserved_videos'], 'storage_bytes' => max(0, $allocatedStorage - $usedStorage)],
            'remaining' => [
                'seconds' => $seconds === null ? null : ($accessible ? max(0, $seconds - $balances['seconds'] - $balances['reserved_seconds']) : 0),
                'videos' => $videos === null ? null : ($accessible ? max(0, $videos - $balances['videos'] - $balances['reserved_videos']) : 0),
                'storage_bytes' => $storage === null ? null : ($accessible ? max(0, $storage - $allocatedStorage) : 0),
            ],
            'recent' => $latest, 'recent_count' => $count,
            'detail_url' => route('admin.usage.customer', $user->id, absolute: false),
        ];
    }

    public function recent(int $userId, array $filters): Builder
    {
        // An export may be retried repeatedly; show a project only once, using its last successful video run.
        $successful = $this->records([...$filters, 'q' => '', 'package_id' => '', 'kind' => '', 'status' => ''])
            ->where('user_id', $userId)->where('kind', 'processing')->whereIn('status', ['charged', 'adjusted'])
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('media_runs')
                ->whereColumn('media_runs.id', 'usage_records.media_run_id')->whereColumn('media_runs.media_project_id', 'usage_records.media_project_id')->where('media_runs.status', 'completed'))
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('media_projects')
                ->whereColumn('media_projects.id', 'usage_records.media_project_id')
                ->whereColumn('media_projects.user_id', 'usage_records.user_id')->whereNotNull('media_projects.output_path'));

        $ranked = $successful->selectRaw('usage_records.id, ROW_NUMBER() OVER (PARTITION BY media_project_id ORDER BY updated_at DESC, id DESC) AS recency');

        return UsageRecord::whereIn('id', DB::query()->fromSub($ranked, 'successful_videos')->select('id')->where('recency', 1));
    }

    public function video(UsageRecord $record): array
    {
        $project = MediaProject::findOrFail($record->media_project_id);

        return ['id' => $record->id, 'project_id' => $project->id, 'title' => $project->title,
            'duration' => $project->metadata['duration'] ?? null,
            'thumbnail' => ($project->metadata['poster'] ?? false) ? route('admin.usage.poster', $record->id, absolute: false) : null,
            'video_url' => route('admin.usage.video', $record->id, absolute: false),
            'completed_at' => $record->updated_at->toIso8601String(),
            'detail_url' => route('admin.usage.show', $record->id, absolute: false)];
    }
}
