<?php

namespace App\Services;

use App\Models\CustomerPackageAssignment;
use App\Models\Package;
use App\Models\PackageEvent;
use App\Models\PackageOperation;
use App\Models\PackageVersion;
use App\Models\User;
use App\Services\Media\StorageSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PackageCatalog
{
    /**
     * Package feature keys. "movie" is Summarize a Long Movie, "dub" is Translate
     * an Existing Recap (narrated) and "subs" is Subtitles. "srt" covers the
     * SRT and styled ASS subtitle files.
     */
    public const FEATURES = ['local', 'youtube', 'movie', 'subs', 'dub', 'mp4', 'srt', 'audio', 'script_edit', 'voice_preview', 'video_preview', 'subtitle_style', 'cover_subtitles', 'layout', 'keep_background'];

    /**
     * Complete feature map for a package definition. Definitions published
     * before the "movie" feature existed inherit it from narrated dubbing.
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, bool>
     */
    public static function features(array $definition): array
    {
        $stored = is_array($definition['features'] ?? null) ? $definition['features'] : [];
        $features = array_fill_keys(self::FEATURES, false);
        foreach (self::FEATURES as $key) {
            $features[$key] = (bool) ($stored[$key] ?? false);
        }
        if (! array_key_exists('movie', $stored)) {
            $features['movie'] = (bool) ($stored['dub'] ?? false);
        }

        return $features;
    }

    /** Studio workflows and the package feature flag each one maps to. */
    public const WORKFLOWS = ['movie' => 'movie', 'translate' => 'dub', 'subtitles' => 'subs'];

    /** included: usable · locked: shown with an upgrade prompt · hidden: not offered. */
    public const ACCESS = ['included', 'locked', 'hidden'];

    public const LABELS = ['movie' => 'Summarize a Long Movie', 'translate' => 'Translate an Existing Recap', 'subtitles' => 'Subtitles'];

    /**
     * Per-workflow rules. "minutes" is that workflow's own allowance per period
     * (null on packages saved before per-workflow allowances: they share the
     * package-wide allowance). Limits left null fall back to the package limits.
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, array{access: string, minutes: ?int, max_video_seconds: ?int, max_target_seconds: ?int}>
     */
    public static function workflows(array $definition): array
    {
        $features = self::features($definition);
        $stored = is_array($definition['workflows'] ?? null) ? $definition['workflows'] : [];
        $result = [];
        foreach (self::WORKFLOWS as $key => $feature) {
            $rule = is_array($stored[$key] ?? null) ? $stored[$key] : [];
            $access = in_array($rule['access'] ?? null, self::ACCESS, true) ? $rule['access'] : ($features[$feature] ? 'included' : 'locked');
            $int = fn (string $field): ?int => isset($rule[$field]) && is_numeric($rule[$field]) && (int) $rule[$field] > 0 ? (int) $rule[$field] : null;
            $result[$key] = ['access' => $access, 'minutes' => $int('minutes'), 'max_video_seconds' => $int('max_video_seconds'),
                'max_target_seconds' => $key === 'movie' ? $int('max_target_seconds') : null];
        }

        return $result;
    }

    /** Which workflow a set of Studio settings runs. */
    public static function workflowOf(array $settings): string
    {
        if (($settings['workflow'] ?? '') === 'movie') {
            return 'movie';
        }

        return ($settings['mode'] ?? 'subs') === 'dub' ? 'translate' : 'subtitles';
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    public static function presentDefinition(array $definition): array
    {
        if (($definition['type'] ?? null) === 'topup') {
            return $definition;
        }

        return [...$definition, 'features' => self::features($definition), 'workflows' => self::workflows($definition), 'watermark' => (bool) ($definition['watermark'] ?? false)];
    }

    public function uploadSizeOptions(): array
    {
        $maximum = (int) floor(app(StorageSettings::class)->limits()['max_bytes'] / 1048576);
        $options = array_filter(config('packages.upload_sizes_mib', []), fn (mixed $size): bool => is_int($size) && $size > 0 && $size <= $maximum);
        if ($maximum > 0) {
            $options[] = $maximum;
        }
        $options = array_values(array_unique($options));
        sort($options, SORT_NUMERIC);

        return $options;
    }

    public function capabilities(): array
    {
        $policy = app(StorageSettings::class)->limits();

        return ['currencies' => config('packages.currencies'), 'upload_size_options_mib' => $this->uploadSizeOptions(), 'max_upload_mib' => (int) floor($policy['max_bytes'] / 1048576), 'max_video_seconds' => (int) $policy['max_duration'], 'storage_gib' => floor($policy['budget'] / 1073741824 * 1000) / 1000, 'retention_enabled' => (bool) $policy['retention_enabled'], 'retention_days' => (int) $policy['keep_days'], 'assignment_ready' => true];
    }

    public function resource(Package $package): array
    {
        return ['id' => $package->id, 'name' => $package->name, 'code' => $package->code, 'type' => $package->type, 'status' => $package->status, 'revision' => $package->revision, 'published_version' => $package->published_version, 'definition' => self::presentDefinition($package->definition ?? []), 'assigned_users' => CustomerPackageAssignment::current()->where('package_id', $package->id)->whereHas('user', fn ($user) => $user->where('is_admin', false)->where('account_status', '!=', 'archived'))->distinct()->count('user_id'), 'created_at' => $package->created_at->toIso8601String(), 'updated_at' => $package->updated_at->toIso8601String()];
    }

    public function details(Package $package): array
    {
        $versions = PackageVersion::where('package_id', $package->id)->leftJoin('users', 'users.id', '=', 'package_versions.actor_id')->orderByDesc('number')->get(['package_versions.id', 'number', 'definition', 'package_versions.created_at', 'users.name as actor'])
            ->map(fn (PackageVersion $version): array => [...$version->toArray(), 'definition' => self::presentDefinition($version->definition ?? [])])->all();
        $events = PackageEvent::where('package_id', $package->id)->leftJoin('users', 'users.id', '=', 'package_events.actor_id')->orderByDesc('package_events.id')->limit(100)->get(['package_events.id', 'package_events.type', 'metadata', 'package_events.created_at', 'users.name as actor'])->toArray();

        return ['package' => $this->resource($package), 'versions' => $versions, 'activity' => $events, 'assignment_ready' => true];
    }

    public function save(User $actor, array $data, ?int $id = null): array
    {
        return $this->execute($actor, $data['request_id'], ['save', $id, $data], function () use ($actor, $data, $id): array {
            $package = $id ? Package::lockForUpdate()->findOrFail($id) : new Package;
            if ($id) {
                $this->assertRevision($package, (int) $data['revision']);
                abort_if($package->status === 'archived', 409, 'Restore this package before editing.');
            }
            if ($package->published_version === 0 && $data['status'] === 'inactive') {
                throw ValidationException::withMessages(['status' => 'Publish a draft before making it inactive.']);
            }
            if ($package->published_version > 0 && $data['code'] !== $package->code) {
                throw ValidationException::withMessages(['code' => 'A published package code cannot change.']);
            }
            if ($package->published_version > 0 && $data['status'] !== $package->status) {
                throw ValidationException::withMessages(['status' => 'Use the explicit activate/deactivate action to change availability.']);
            }
            $definition = $this->normalize($data);
            $publish = $data['status'] === 'active' || $package->published_version > 0;
            if ($publish) {
                $this->validateForPublication($definition);
            }
            $changed = ! $id || $package->definition != $definition;
            if ($id && $changed && $package->published_version > 0) {
                if (! ($data['confirm_version'] ?? false)) {
                    throw ValidationException::withMessages(['confirm_version' => 'Review and confirm the new version.']);
                }
                if (! filled($data['reason'] ?? null)) {
                    throw ValidationException::withMessages(['reason' => 'Give a reason for this published package change.']);
                }
            }
            if ($id && ! $changed && $package->status === $data['status']) {
                return ['package' => $this->resource($package), 'unchanged' => true];
            }
            $from = $package->status;
            $previous = $package->published_version;
            $package->forceFill(['name' => $data['name'], 'code' => $data['code'], 'type' => $data['type'], 'status' => $data['status'], 'definition' => $definition, 'updated_by' => $actor->id]);
            if (! $id) {
                $package->created_by = $actor->id;
            } else {
                $package->revision++;
            }
            $package->save();
            if ($publish && ($changed || $package->published_version === 0)) {
                $this->publishVersion($package, $actor);
            }
            $this->event($package, $actor, $id ? 'edited' : 'created', ['from' => $from, 'to' => $package->status, 'previous_version' => $previous, 'version' => $package->published_version, 'reason' => $data['reason'] ?? null, 'source_id' => $data['source_id'] ?? null]);

            return ['package' => $this->resource($package->refresh()), 'unchanged' => false];
        });
    }

    public function action(User $actor, string $key, array $items, string $action, string $reason): array
    {
        return $this->execute($actor, $key, [$action, $items, $reason], function () use ($actor, $items, $action, $reason): array {
            $results = [];
            foreach ($items as $item) {
                $package = Package::lockForUpdate()->findOrFail($item['id']);
                $this->assertRevision($package, (int) $item['revision']);
                $from = $package->status;
                $allowed = ['publish' => ['draft'], 'activate' => ['inactive'], 'deactivate' => ['active'], 'archive' => ['draft', 'active', 'inactive'], 'restore' => ['archived']];
                abort_unless(isset($allowed[$action]) && in_array($from, $allowed[$action], true), 409, 'Package status changed. Refresh and try again.');
                $to = match ($action) {
                    'publish','activate' => 'active','deactivate' => 'inactive','archive' => 'archived','restore' => $package->published_version > 0 ? 'inactive' : 'draft'
                };
                if ($to === 'active') {
                    $this->validateForPublication($package->definition);
                }
                if ($to === 'active' && $package->published_version === 0) {
                    $this->publishVersion($package, $actor);
                }
                $package->forceFill(['status' => $to, 'revision' => $package->revision + 1, 'updated_by' => $actor->id])->save();
                $this->event($package, $actor, $action, ['from' => $from, 'to' => $to, 'version' => $package->published_version, 'reason' => $reason]);
                $results[] = $this->resource($package->refresh());
            }

            return ['packages' => $results];
        });
    }

    private function execute(User $actor, string $key, array $payload, callable $action): array
    {
        abort_unless($actor->is_admin, 403);
        $fingerprint = hash_hmac('sha256', json_encode([$actor->id, $payload], JSON_THROW_ON_ERROR), (string) config('app.key'));

        return Cache::lock('package-catalog-write', 30)->block(5, fn (): array => DB::transaction(function () use ($actor, $key, $fingerprint, $action): array {
            if ($operation = PackageOperation::find($key)) {
                abort_unless($operation->actor_id === $actor->id && hash_equals($operation->fingerprint, $fingerprint), 409, 'This request identity has already been used.');

                return [...$operation->result, 'replayed' => true];
            }
            $result = $action();
            PackageOperation::create(['id' => $key, 'actor_id' => $actor->id, 'fingerprint' => $fingerprint, 'result' => $result]);

            return [...$result, 'replayed' => false];
        }));
    }

    private function assertRevision(Package $package, int $revision): void
    {
        abort_unless($package->revision === $revision, 409, 'This package changed. Refresh before editing again.');
    }

    private function publishVersion(Package $package, User $actor): void
    {
        $package->published_version++;
        $package->save();
        PackageVersion::create(['package_id' => $package->id, 'number' => $package->published_version, 'definition' => $package->definition, 'actor_id' => $actor->id]);
    }

    private function event(Package $package, User $actor, string $type, array $metadata): void
    {
        PackageEvent::create(['package_id' => $package->id, 'actor_id' => $actor->id, 'type' => $type, 'metadata' => $metadata]);
    }

    private function normalize(array $data): array
    {
        if ($data['type'] === 'topup') {
            return $this->normalizeTopup($data);
        }
        $definition = array_intersect_key($data, array_flip(['name', 'code', 'type', 'currency', 'validity_days', 'included_minutes', 'video_limit', 'storage_gib', 'max_video_seconds', 'max_upload_mib', 'concurrency', 'queue_limit', 'retention_days', 'description', 'note']));
        foreach (['validity_days', 'included_minutes', 'video_limit', 'max_video_seconds', 'max_upload_mib', 'concurrency', 'queue_limit', 'retention_days'] as $field) {
            $definition[$field] = isset($data[$field]) ? (int) $data[$field] : null;
        }
        $definition['currency'] = $data['currency'] ?? null;
        $definition['storage_gib'] = isset($data['storage_gib']) ? (float) $data['storage_gib'] : null;
        $price = isset($data['price']) ? (string) $data['price'] : ($data['type'] === 'trial' ? '0' : null);
        if ($price !== null) {
            $parts = explode('.', $price);
            $minor = (int) $parts[0] * 100 + (int) str_pad($parts[1] ?? '', 2, '0');
        } else {
            $minor = null;
        }
        if ($data['type'] === 'trial' && $minor !== 0) {
            throw ValidationException::withMessages(['price' => 'A free trial must have a zero price.']);
        }
        $definition['price_minor'] = $minor;
        $definition['price'] = $minor === null ? null : sprintf('%d.%02d', intdiv($minor, 100), $minor % 100);
        $definition['included_seconds'] = $definition['included_minutes'] === null ? null : $definition['included_minutes'] * 60;
        $definition['storage_bytes'] = $definition['storage_gib'] === null ? null : (int) floor($definition['storage_gib'] * 1073741824);
        $definition['max_upload_bytes'] = $definition['max_upload_mib'] === null ? null : $definition['max_upload_mib'] * 1048576;
        $definition['description'] = $data['description'] ?? '';
        $definition['note'] = $data['note'] ?? '';
        $definition['features'] = array_fill_keys(self::FEATURES, false);
        foreach ($data['features'] ?? [] as $key => $value) {
            $definition['features'][$key] = (bool) $value;
        }
        $definition['watermark'] = (bool) ($data['watermark'] ?? false);
        if (is_array($data['workflows'] ?? null)) {
            $workflows = [];
            $total = 0;
            $complete = true;
            foreach (self::WORKFLOWS as $key => $feature) {
                $rule = is_array($data['workflows'][$key] ?? null) ? $data['workflows'][$key] : [];
                $access = in_array($rule['access'] ?? null, self::ACCESS, true) ? $rule['access'] : 'locked';
                $minutes = isset($rule['minutes']) && $rule['minutes'] !== '' ? (int) $rule['minutes'] : null;
                $workflows[$key] = ['access' => $access, 'minutes' => $minutes,
                    'max_video_seconds' => isset($rule['max_video_seconds']) && $rule['max_video_seconds'] !== '' ? (int) $rule['max_video_seconds'] : null,
                    'max_target_seconds' => $key === 'movie' && isset($rule['max_target_seconds']) && $rule['max_target_seconds'] !== '' ? (int) $rule['max_target_seconds'] : null];
                // The workflow switch is the source of truth for the matching Studio feature flag.
                $definition['features'][$feature] = $access === 'included';
                if ($access === 'included') {
                    $minutes === null ? $complete = false : $total += $minutes;
                }
            }
            $definition['workflows'] = $workflows;
            // The package-wide allowance is the sum of the workflow allowances (shown on plan cards).
            $definition['included_minutes'] = $complete && $total > 0 ? $total : null;
            $definition['included_seconds'] = $definition['included_minutes'] === null ? null : $definition['included_minutes'] * 60;
        }

        return $definition;
    }

    /** A Top-up adds minutes to one workflow for the rest of the customer's current period. */
    private function normalizeTopup(array $data): array
    {
        $price = isset($data['price']) ? (string) $data['price'] : null;
        $minor = null;
        if ($price !== null) {
            $parts = explode('.', $price);
            $minor = (int) $parts[0] * 100 + (int) str_pad($parts[1] ?? '', 2, '0');
        }

        return ['name' => $data['name'], 'code' => $data['code'], 'type' => 'topup', 'currency' => $data['currency'] ?? null,
            'price_minor' => $minor, 'price' => $minor === null ? null : sprintf('%d.%02d', intdiv($minor, 100), $minor % 100),
            'topup' => ['workflow' => in_array($data['topup_workflow'] ?? null, array_keys(self::WORKFLOWS), true) ? $data['topup_workflow'] : null,
                'minutes' => isset($data['topup_minutes']) && $data['topup_minutes'] !== '' ? (int) $data['topup_minutes'] : null],
            'description' => $data['description'] ?? '', 'note' => $data['note'] ?? ''];
    }

    private function validateForPublication(array $definition): void
    {
        $errors = [];
        if (($definition['type'] ?? null) === 'topup') {
            if (! filled($definition['currency'] ?? null)) {
                $errors['currency'] = 'Complete this field before publishing.';
            }
            if (($definition['price_minor'] ?? 0) <= 0) {
                $errors['price'] = 'A top-up needs a positive price.';
            }
            if (! ($definition['topup']['workflow'] ?? null)) {
                $errors['topup_workflow'] = 'Choose which workflow these minutes are for.';
            }
            if (($definition['topup']['minutes'] ?? 0) < 1) {
                $errors['topup_minutes'] = 'Enter the minutes this top-up adds.';
            }
            if ($errors) {
                throw ValidationException::withMessages($errors);
            }

            return;
        }
        $workflows = self::workflows($definition);
        $required = isset($definition['workflows']) ? ['currency', 'validity_days', 'storage_gib', 'max_video_seconds', 'max_upload_mib', 'concurrency', 'queue_limit', 'retention_days']
            : ['currency', 'validity_days', 'included_minutes', 'storage_gib', 'max_video_seconds', 'max_upload_mib', 'concurrency', 'queue_limit', 'retention_days'];
        foreach ($workflows as $key => $rule) {
            if ($rule['access'] !== 'included' || ! isset($definition['workflows'])) {
                continue;
            }
            if ($rule['minutes'] === null) {
                $errors['workflows.'.$key.'.minutes'] = 'Set the minutes included for this workflow.';
            } elseif (min($rule['max_video_seconds'] ?? PHP_INT_MAX, $definition['max_video_seconds'] ?? PHP_INT_MAX) > $rule['minutes'] * 60) {
                $errors['workflows.'.$key.'.minutes'] = 'The minutes must cover at least one maximum-length video for this workflow.';
            }
            if ($rule['max_video_seconds'] !== null && $rule['max_video_seconds'] > ($definition['max_video_seconds'] ?? 0)) {
                $errors['workflows.'.$key.'.max_video_seconds'] = 'This cannot exceed the package maximum video duration.';
            }
        }
        foreach ($required as $field) {
            if (! isset($definition[$field]) || $definition[$field] === '') {
                $errors[$field] = 'Complete this field before publishing.';
            }
        }
        if (($definition['type'] ?? 'paid') === 'paid' && ($definition['price_minor'] ?? 0) <= 0) {
            $errors['price'] = 'A paid package needs a positive price.';
        }
        $limits = $this->capabilities();
        if (($definition['max_upload_mib'] ?? 0) > $limits['max_upload_mib']) {
            $errors['max_upload_mib'] = 'This exceeds the system upload limit.';
        }
        if (($definition['max_video_seconds'] ?? 0) > $limits['max_video_seconds']) {
            $errors['max_video_seconds'] = 'This exceeds the system video duration limit.';
        }
        if (($definition['max_upload_bytes'] ?? 0) > ($definition['storage_bytes'] ?? 0)) {
            $errors['storage_gib'] = 'Customer storage must fit at least one allowed upload.';
        }
        if (! isset($definition['workflows']) && ($definition['max_video_seconds'] ?? 0) > ($definition['included_seconds'] ?? 0)) {
            $errors['included_minutes'] = 'Included usage must cover at least one maximum-length video.';
        }
        if ($limits['retention_enabled'] && ($definition['retention_days'] ?? 0) > $limits['retention_days']) {
            $errors['retention_days'] = 'This exceeds the system retention ceiling.';
        }
        $f = self::features($definition);
        if (! $f['local'] && ! $f['youtube']) {
            $errors['features.local'] = 'Enable at least one input source.';
        }
        if (! $f['subs'] && ! $f['dub'] && ! $f['movie']) {
            $errors[isset($definition['workflows']) ? 'workflows.subtitles.access' : 'features.subs'] = 'Include at least one workflow.';
        }
        if (! $f['mp4'] && ! $f['srt'] && ! $f['audio']) {
            $errors['features.mp4'] = 'Enable at least one export.';
        }
        if ($f['subs'] && ! $f['mp4'] && ! $f['srt']) {
            $errors['features.srt'] = 'Subtitles mode requires MP4 or SRT export.';
        }
        if (($f['dub'] || $f['movie']) && ! $f['mp4'] && ! $f['audio']) {
            $errors['features.mp4'] = 'Narrated recaps require MP4 or audio export.';
        }
        if (($f['audio'] || $f['voice_preview'] || $f['keep_background']) && ! $f['dub'] && ! $f['movie']) {
            $errors['features.dub'] = 'Enable a narrated recap mode for narration audio, voice preview or background separation.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }
}
