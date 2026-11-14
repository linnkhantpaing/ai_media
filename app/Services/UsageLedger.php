<?php

namespace App\Services;

use App\Exceptions\MediaFailure;
use App\Models\CustomerPackageAssignment;
use App\Models\MediaProject;
use App\Models\MediaRun;
use App\Models\UsageEvent;
use App\Models\UsageRecord;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UsageLedger
{
    public function featureErrors(MediaProject $project, array $settings, ?CustomerPackageAssignment $assignment = null): array
    {
        $assignment ??= CustomerPackageAssignment::current()->where('user_id', $project->user_id)->first();
        if (! $assignment) {
            return [];
        }
        $snapshot = $assignment->effectiveSnapshot();
        $features = $snapshot['definition']['features'] ?? [];
        $mode = $settings['mode'] ?? 'subs';
        $output = $settings['output'] ?? 'burn';
        $required = ['usage' => $project->source_type === 'youtube' ? 'youtube' : 'local', 'settings.mode' => $mode,
            'settings.output' => in_array($output, ['mp3', 'wav'], true) ? 'audio' : ($output === 'srt' ? 'srt' : 'mp4')];
        if ($mode === 'dub' && ($settings['keep_background'] ?? false) && ! (in_array($output, ['mp3', 'wav'], true) && ($settings['audio_track'] ?? 'mix') === 'narration')) {
            $required['settings.keep_background'] = 'keep_background';
        }
        $labels = ['youtube' => 'YouTube Import', 'local' => 'Local Video', 'subs' => 'Subtitles Only', 'dub' => 'Full Burmese Dub', 'audio' => 'Audio Export', 'mp4' => 'MP4 Video', 'srt' => 'SRT Subtitles', 'keep_background' => 'Keep Music & Effects'];
        $errors = [];
        foreach ($required as $field => $feature) {
            if (! ($features[$feature] ?? false)) {
                $errors[$field] = [$snapshot['name'].' (Version '.$snapshot['version'].') does not include '.$labels[$feature].'. Contact an administrator to change this package\'s features.'];
            }
        }

        return $errors;
    }

    public function quote(MediaProject $project): ?array
    {
        if (! ($project->metadata['duration'] ?? 0)) {
            return null;
        }
        $a = CustomerPackageAssignment::current()->where('user_id', $project->user_id)->first();
        if (! $a) {
            return null;
        }
        $anchor = $a->allowance_starts_at ?? $a->starts_at;
        $b = $this->balances($project->user_id, $anchor);
        $d = $a->effectiveSnapshot()['definition'];
        $counted = UsageRecord::where('user_id', $project->user_id)->where('media_project_id', $project->id)->where('allowance_start', $anchor)->whereRaw('charged_videos > returned_videos')->exists();

        return ['estimated_seconds' => (int) ceil($project->metadata['duration']), 'videos' => $counted ? 0 : 1, 'available_seconds' => max(0, ($d['included_seconds'] ?? ($d['included_minutes'] ?? 0) * 60) - $b['seconds'] - $b['reserved_seconds'])];
    }

    public function balances(int $userId, mixed $anchor): array
    {
        $query = UsageRecord::where('user_id', $userId)->where('kind', 'processing');
        $anchor ? $query->where('allowance_start', $anchor) : $query->whereNull('allowance_start');

        return ['seconds' => (int) (clone $query)->selectRaw('COALESCE(SUM(charged_seconds-returned_seconds),0) AS amount')->value('amount'),
            'videos' => (int) (clone $query)->selectRaw('COALESCE(SUM(charged_videos-returned_videos),0) AS amount')->value('amount'),
            'reserved_seconds' => (int) (clone $query)->sum('reserved_seconds'), 'reserved_videos' => (int) (clone $query)->sum('reserved_videos')];
    }

    public function reserve(MediaRun $run): UsageRecord
    {
        return DB::transaction(function () use ($run): UsageRecord {
            $project = $run->project()->firstOrFail();
            $user = User::lockForUpdate()->findOrFail($project->user_id);
            $existing = UsageRecord::where('media_run_id', $run->id)->lockForUpdate()->first();
            if ($existing && in_array($existing->status, ['reserved', 'charged', 'adjusted'], true)) {
                return $existing;
            }
            if ($user->account_status !== 'active') {
                $this->invalid('This account cannot start processing.');
            }
            $assignment = CustomerPackageAssignment::current()->where('user_id', $user->id)->first();
            if (($user->managed_account || $assignment) && (! $assignment || $assignment->status() !== 'active')) {
                $this->invalid('An active subscription is required.');
            }
            $snapshot = $assignment?->effectiveSnapshot();
            $definition = $snapshot['definition'] ?? [];
            $anchor = $assignment?->allowance_starts_at ?? $assignment?->starts_at;
            if ($existing && $existing->allowance_start?->toDateTimeString() !== $anchor?->toDateTimeString()) {
                $this->invalid('This operation belongs to an earlier allowance period. Start a new analysis.');
            }
            $seconds = (int) ceil((float) ($project->metadata['duration'] ?? 0));
            if ($seconds < 1) {
                $this->invalid('The source video duration has not been verified.');
            }
            $totals = $this->balances($user->id, $anchor);
            $prior = UsageRecord::where('user_id', $user->id)->where('media_project_id', $project->id)->where('kind', 'processing')
                ->where(fn ($q) => $q->where('reserved_videos', '>', 0)->orWhereRaw('charged_videos > returned_videos'));
            $anchor ? $prior->where('allowance_start', $anchor) : $prior->whereNull('allowance_start');
            $videos = $prior->exists() ? 0 : 1;
            if ($assignment) {
                if ($seconds > ($definition['max_video_seconds'] ?? 0)) {
                    $this->invalid('The video exceeds the maximum duration for this package.');
                }
                if ($totals['seconds'] + $totals['reserved_seconds'] + $seconds > ($definition['included_seconds'] ?? ($definition['included_minutes'] ?? 0) * 60)) {
                    $this->invalid('There is not enough processing allowance for this video.');
                }
                if (isset($definition['video_limit']) && $definition['video_limit'] < $totals['videos'] + $totals['reserved_videos'] + $videos) {
                    $this->invalid('The video allowance for this period has been used.');
                }
                $featureErrors = $this->featureErrors($project, $run->settings, $assignment);
                if ($featureErrors !== []) {
                    throw ValidationException::withMessages($featureErrors);
                }
                $active = MediaRun::whereHas('project', fn ($q) => $q->where('user_id', $user->id))->where('id', '!=', $run->id)
                    ->whereIn('status', ['queued', 'retry_waiting', 'analyzing', 'translating', 'rendering', 'cancel_requested'])->count();
                if ($active >= ($definition['concurrency'] ?? 1) + ($definition['queue_limit'] ?? 1)) {
                    $this->invalid('The processing queue limit for this account has been reached.');
                }
            }
            $record = $existing ?? new UsageRecord(['operation_key' => 'run:'.$run->id, 'media_run_id' => $run->id, 'media_project_id' => $project->id, 'user_id' => $user->id,
                'assignment_id' => $assignment?->id, 'package_id' => $assignment?->package_id, 'kind' => 'processing',
                'customer_snapshot' => ['name' => $user->name, 'email' => $user->email],
                'project_snapshot' => ['title' => $project->title, 'duration' => $seconds],
                'package_snapshot' => $snapshot, 'period_start' => $assignment?->starts_at, 'period_end' => $assignment?->ends_at, 'allowance_start' => $anchor]);
            $record->fill(['status' => 'reserved', 'reserved_seconds' => $seconds, 'reserved_videos' => $videos, 'revision' => ($existing?->revision ?? 0) + 1])->save();
            $this->event($record, 'reserved', ['seconds' => $seconds, 'videos' => $videos]);

            return $record;
        }, 5);
    }

    public function claim(MediaRun $run): bool
    {
        return DB::transaction(function () use ($run): bool {
            $project = $run->project()->first();
            if (! $project) {
                return false;
            }
            $user = User::lockForUpdate()->findOrFail($project->user_id);
            $record = UsageRecord::where('media_run_id', $run->id)->first();
            $definition = $record?->package_snapshot['definition'] ?? null;
            if (! $definition) {
                return true;
            }
            $busy = MediaRun::whereHas('project', fn ($q) => $q->where('user_id', $user->id))->where('id', '!=', $run->id)
                ->whereIn('status', ['analyzing', 'translating', 'rendering', 'cancel_requested'])->count();
            if ($busy >= ($definition['concurrency'] ?? 1)) {
                return false;
            }
            $run->update(['status' => $run->phase === 'generate' ? 'rendering' : 'analyzing']);

            return true;
        }, 5);
    }

    public function settle(MediaRun $run): void
    {
        $this->transition($run, true);
    }

    public function release(MediaRun $run): void
    {
        $this->transition($run, false);
    }

    private function transition(MediaRun $run, bool $charge): void
    {
        DB::transaction(function () use ($run, $charge): void {
            $userId = $run->project()->value('user_id');
            if ($userId) {
                User::whereKey($userId)->lockForUpdate()->first();
            }
            $record = UsageRecord::where('media_run_id', $run->id)->lockForUpdate()->first();
            if (! $record || $record->status !== 'reserved') {
                return;
            }
            $seconds = $record->reserved_seconds;
            $videos = $record->reserved_videos;
            $record->fill(['status' => $charge ? 'charged' : 'released', 'reserved_seconds' => 0, 'reserved_videos' => 0,
                'charged_seconds' => $charge ? $seconds : $record->charged_seconds, 'charged_videos' => $charge ? $videos : $record->charged_videos, 'revision' => $record->revision + 1])->save();
            $this->event($record, $charge ? 'charged' : 'released', ['seconds' => $seconds, 'videos' => $videos]);
        }, 5);
    }

    public function adjust(User $actor, UsageRecord $record, array $data): UsageRecord
    {
        return DB::transaction(function () use ($actor, $record, $data): UsageRecord {
            User::whereKey($record->user_id)->lockForUpdate()->first();
            $record = UsageRecord::lockForUpdate()->findOrFail($record->id);
            $previous = UsageEvent::where('request_id', $data['request_id'])->first();
            $change = ['seconds' => (int) $data['seconds'], 'videos' => (int) $data['videos']];
            if ($previous) {
                abort_unless($previous->usage_record_id === $record->id && $previous->actor_id === $actor->id && $previous->changes === $change && $previous->reason === trim($data['reason']), 409, 'This request was already used.');

                return $record;
            }
            abort_unless($record->revision === (int) $data['revision'], 409, 'This usage record changed. Refresh and try again.');
            if ($record->kind !== 'processing' || ! in_array($record->status, ['charged', 'adjusted'], true)) {
                $this->invalid('Only a completed processing charge can be corrected.');
            }
            if ($change['seconds'] + $change['videos'] < 1) {
                $this->invalid('Enter processing seconds or videos to return.');
            }
            if ($change['seconds'] > $record->charged_seconds - $record->returned_seconds || $change['videos'] > $record->charged_videos - $record->returned_videos) {
                $this->invalid('The correction exceeds the remaining original charge.');
            }
            $record->fill(['returned_seconds' => $record->returned_seconds + $change['seconds'], 'returned_videos' => $record->returned_videos + $change['videos'], 'status' => 'adjusted', 'revision' => $record->revision + 1])->save();
            $this->event($record, 'adjusted', $change, $actor, trim($data['reason']), $data['request_id']);

            return $record;
        }, 5);
    }

    public function storageBytes(?int $userId = null): int
    {
        return (int) DB::table('media_files')->join('media_projects', 'media_projects.id', '=', 'media_files.media_project_id')
            ->whereNotNull('media_files.verified_at')->where('media_files.state', '!=', 'deleted')
            ->when($userId, fn ($q) => $q->where('media_projects.user_id', $userId))->sum('media_files.bytes');
    }

    public function allocatedStorage(int $userId): int
    {
        $objects = (int) DB::table('media_files')->join('media_projects', 'media_projects.id', '=', 'media_files.media_project_id')->where('media_projects.user_id', $userId)->where('media_files.state', '!=', 'deleted')->sum('media_files.bytes');
        $pending = (int) MediaProject::where('user_id', $userId)->whereIn('status', ['uploading', 'importing', 'validating'])->whereNotExists(function ($q): void {
            $q->selectRaw('1')->from('media_files')->whereColumn('media_files.media_project_id', 'media_projects.id')->where('is_source', true)->where('state', '!=', 'deleted');
        })->sum('expected_bytes');

        return $objects + $pending;
    }

    public function checkUpload(int $userId, int $bytes, string $source): void
    {
        $user = User::findOrFail($userId);
        $a = $user->currentPackageAssignment;
        if (! $a) {
            return;
        }
        $d = $a->effectiveSnapshot()['definition'] ?? [];
        if (! ($d['features'][$source] ?? false)) {
            throw new MediaFailure('source_not_ready');
        }
        if ($bytes > ($d['max_upload_bytes'] ?? ($d['max_upload_mib'] ?? 0) * 1048576)) {
            throw new MediaFailure('too_large');
        }
        if ($this->allocatedStorage($userId) + $bytes > ($d['storage_bytes'] ?? 0)) {
            throw new MediaFailure('storage_limit');
        }
    }

    public function checkStorage(MediaProject $project, int $additional): void
    {
        $user = User::findOrFail($project->user_id);
        $a = $user->currentPackageAssignment;
        if (! $a) {
            return;
        }
        $limit = $a->effectiveSnapshot()['definition']['storage_bytes'] ?? null;
        if ($limit !== null && $this->allocatedStorage($user->id) + $additional > $limit) {
            throw new MediaFailure('storage_limit');
        }
    }

    public function syncStorage(MediaProject $project, bool $opening = false): void
    {
        DB::transaction(function () use ($project, $opening): void {
            $user = User::whereKey($project->user_id)->lockForUpdate()->first();
            if (! $user) {
                return;
            }
            $a = $user->currentPackageAssignment;
            foreach (DB::table('media_files')->where('media_project_id', $project->id)->where(fn ($q) => $q->whereNotNull('verified_at')->orWhere('state', 'deleted'))->get() as $file) {
                $key = 'file:'.$file->id;
                $record = UsageRecord::where('operation_key', $key)->lockForUpdate()->first();
                $bytes = $file->state === 'deleted' ? 0 : (int) $file->bytes;
                if (! $record && ! $bytes) {
                    continue;
                }
                if ($record && $record->storage_bytes === $bytes) {
                    continue;
                }
                $old = $record?->storage_bytes ?? 0;
                $record ??= new UsageRecord(['operation_key' => $key, 'user_id' => $user->id, 'media_project_id' => $project->id, 'assignment_id' => $a?->id, 'package_id' => $a?->package_id, 'kind' => 'storage',
                    'customer_snapshot' => ['name' => $user->name, 'email' => $user->email], 'project_snapshot' => ['title' => $project->title, 'file' => basename($file->path), 'category' => $file->is_source ? 'source' : (pathinfo($file->path, PATHINFO_EXTENSION) ?: 'file')],
                    'package_snapshot' => $a?->effectiveSnapshot(), 'period_start' => $a?->starts_at, 'period_end' => $a?->ends_at, 'allowance_start' => $a?->allowance_starts_at ?? $a?->starts_at]);
                $record->fill(['status' => $bytes ? 'stored' : 'released', 'storage_bytes' => $bytes, 'revision' => ($record->revision ?? 0) + 1])->save();
                $this->event($record, $old === 0 ? ($opening ? 'reconcile' : 'stored') : ($bytes === 0 ? 'deleted' : 'storage_updated'), ['bytes' => $bytes - $old]);
            }
        }, 5);
    }

    public function discardStorage(MediaProject $project): void
    {
        DB::table('media_files')->where('media_project_id', $project->id)->update(['state' => 'deleted', 'updated_at' => now()]);
        $this->syncStorage($project);
    }

    public function event(UsageRecord $record, string $kind, array $changes, ?User $actor = null, ?string $reason = null, ?string $requestId = null): void
    {
        $record->events()->create(['kind' => $kind, 'changes' => $changes, 'actor_id' => $actor?->id, 'actor_name' => $actor?->name, 'reason' => $reason, 'request_id' => $requestId, 'created_at' => now()]);
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['usage' => [$message]]);
    }
}
