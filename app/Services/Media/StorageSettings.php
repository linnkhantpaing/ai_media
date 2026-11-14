<?php

namespace App\Services\Media;

use App\Models\MediaProject;
use App\Services\AuditTrail;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class StorageSettings
{
    private ?array $accepted = null;

    public function baselinePolicy(): array
    {
        return ['max_bytes' => (int) config('media.max_upload_bytes'), 'max_duration' => (int) config('media.max_duration_seconds'), 'budget' => (int) config('media.storage_limit_bytes'), 'retention_enabled' => false, 'keep_days' => 30, 'source_days' => 30, 'artifact_days' => 30, 'cache_hours' => 24, 'cleanup_cache' => true];
    }

    public function baselineConnection(): array
    {
        return ['name' => 'AI Media R2', 'disk' => config('filesystems.media_disk', 'local'), 'endpoint' => (string) config('filesystems.disks.r2.endpoint'), 'bucket' => (string) config('filesystems.disks.r2.bucket'), 'prefix' => (string) config('filesystems.disks.r2.root'), 'key' => (string) config('filesystems.disks.r2.key'), 'secret' => (string) config('filesystems.disks.r2.secret')];
    }

    public function policy(): array
    {
        $row = DB::table('media_storage_settings')->find(1);

        return $row?->policy ? array_replace($this->baselinePolicy(), json_decode($row->policy, true, 512, JSON_THROW_ON_ERROR)) : $this->baselinePolicy();
    }

    public function limits(): array
    {
        return $this->accepted ?? $this->policy();
    }

    public function withPolicy(?array $policy, callable $action): mixed
    {
        $old = $this->accepted;
        $this->accepted = $policy ?? $this->policy();
        try {
            return $action();
        } finally {
            $this->accepted = $old;
        }
    }

    public function connection(): array
    {
        $id = DB::table('media_storage_settings')->where('id', 1)->value('profile_id');

        return $id ? $this->profile((int) $id) : $this->baselineConnection();
    }

    public function profile(int $id): array
    {
        $row = DB::table('media_storage_profiles')->find($id);
        abort_unless($row, 503, 'storage_not_configured');

        return json_decode(Crypt::decryptString($row->configuration), true, 512, JSON_THROW_ON_ERROR);
    }

    public function fingerprint(array $connection): string
    {
        return hash_hmac('sha256', json_encode($connection, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    public function capture(?array $connection = null): int
    {
        $connection ??= $this->connection();
        $hash = $this->fingerprint($connection);
        DB::table('media_storage_profiles')->insertOrIgnore(['fingerprint' => $hash, 'configuration' => Crypt::encryptString(json_encode($connection, JSON_THROW_ON_ERROR)), 'created_at' => now()]);

        return (int) DB::table('media_storage_profiles')->where('fingerprint', $hash)->value('id');
    }

    public function filesystem(?int $id = null, string $legacy = 'r2'): Filesystem
    {
        if (! $id) {
            return Storage::disk($legacy);
        }
        $connection = $this->profile($id);
        if ($connection['disk'] === 'local') {
            return Storage::disk('local');
        }
        if ($connection === $this->baselineConnection()) {
            return Storage::disk('r2');
        }

        return Storage::build(['driver' => 's3', 'key' => $connection['key'], 'secret' => $connection['secret'], 'endpoint' => $connection['endpoint'], 'bucket' => $connection['bucket'], 'root' => $connection['prefix'], 'region' => 'auto', 'use_path_style_endpoint' => true, 'throw' => true, 'report' => false, 'retries' => 1, 'http' => ['connect_timeout' => 10, 'timeout' => 120, 'allow_redirects' => false]]);
    }

    public function candidate(array $input): array
    {
        $current = $this->connection();
        $candidate = array_replace($current, array_intersect_key($input, array_flip(['name', 'endpoint', 'bucket', 'prefix'])));
        foreach (['name', 'endpoint', 'bucket', 'prefix'] as $field) {
            $candidate[$field] = trim((string) $candidate[$field]);
        } $candidate['endpoint'] = rtrim($candidate['endpoint'], '/');
        foreach (['key', 'secret'] as $field) {
            if (trim((string) ($input[$field] ?? '')) !== '') {
                $candidate[$field] = trim($input[$field]);
            }
        }
        if ($candidate['disk'] === 'r2' && (! $candidate['key'] || ! $candidate['secret'])) {
            throw ValidationException::withMessages(['connection.secret' => 'Storage credentials are required.']);
        }

        return $candidate;
    }

    public function check(array $connection, int $user): array
    {
        $path = 'checks/'.Str::uuid().'.txt';
        $disk = null;
        $success = false;
        try {
            $id = $this->capture($connection);
            $disk = $this->filesystem($id, $connection['disk']);
            $data = 'Private media check '.Str::uuid();
            if (! $disk->put($path, $data) || $disk->size($path) !== strlen($data) || ! hash_equals(hash('sha256', $data), hash('sha256', $disk->get($path)))) {
                throw new \RuntimeException('verification');
            }$disk->delete($path);
            $success = ! $disk->exists($path);
        } catch (Throwable) {
            $success = false;
        } finally {
            if ($disk) {
                try {
                    $disk->delete($path);
                } catch (Throwable) {
                    $success = false;
                }
            }
        }
        $auditId = DB::table('media_storage_checks')->insertGetId(['fingerprint' => $this->fingerprint($connection), 'user_id' => $user, 'success' => $success, 'checked_at' => now()]);

        app(AuditTrail::class)->capture('storage_check', $auditId);

        return ['success' => $success, 'checkedAt' => now()->toIso8601String(), 'code' => $success ? null : 'storage_unavailable'];
    }

    public function present(): array
    {
        $row = DB::table('media_storage_settings')->find(1);
        $connection = $this->connection();
        $check = DB::table('media_storage_checks')->where('fingerprint', $this->fingerprint($connection))->latest('id')->first();
        $safe = array_intersect_key($connection, array_flip(['name', 'disk', 'endpoint', 'bucket', 'prefix']));
        $safe['keyMasked'] = $connection['key'] ? '********'.substr($connection['key'], -4) : null;
        $safe['hasSecret'] = $connection['secret'] !== '';

        return ['revision' => (int) ($row?->revision ?? 0), 'savedAt' => $row?->updated_at ? Carbon::parse($row->updated_at)->toIso8601String() : null, 'connection' => $safe, 'policy' => $this->policy(), 'check' => $check ? ['success' => (bool) $check->success, 'checkedAt' => Carbon::parse($check->checked_at)->toIso8601String()] : null];
    }

    public function save(array $data, int $user): void
    {
        Cache::lock('media-storage-reservation', 30)->block(10, fn () => $this->saveLocked($data, $user));
    }

    private function saveLocked(array $data, int $user): void
    {
        $connection = $this->candidate($data['connection']);
        $current = $this->connection();
        if ($connection !== $current) {
            $verified = DB::table('media_storage_checks')->where('fingerprint', $this->fingerprint($connection))->where('user_id', $user)->where('success', true)->where('checked_at', '>=', now()->subMinutes(10))->exists();
            if (! $verified) {
                throw ValidationException::withMessages(['connection' => 'Test the changed connection before saving.']);
            }
        }
        if ($data['policy']['budget'] < app(MediaStorage::class)->reservedBytes() && ! ($data['confirm_over_budget'] ?? false)) {
            throw ValidationException::withMessages(['policy.budget' => 'Confirm the reduced storage budget.']);
        }
        DB::transaction(function () use ($data, $user, $connection, $current): void {
            $head = DB::table('media_storage_settings')->where('id', 1)->lockForUpdate()->first();
            abort_if((int) $head->revision !== (int) $data['revision'], 409, 'settings_conflict');
            DB::table('media_projects')->whereNull('storage_policy')->update(['storage_policy' => json_encode($this->policy(), JSON_THROW_ON_ERROR)]);
            foreach (['r2', 'local'] as $disk) {
                $legacy = DB::table('media_projects')->whereNull('storage_profile_id')->where('storage_disk', $disk);
                if ($legacy->exists()) {
                    $old = $current;
                    $old['disk'] = $disk;
                    $legacy->update(['storage_profile_id' => $this->capture($old)]);
                }
            }
            DB::table('media_storage_settings')->where('id', 1)->update(['profile_id' => $this->capture($connection), 'policy' => json_encode($data['policy'], JSON_THROW_ON_ERROR), 'revision' => $head->revision + 1, 'updated_by' => $user, 'created_at' => $head->created_at ?? now(), 'updated_at' => now()]);
            app(AuditTrail::class)->settings('storage', $user);
            if ($data['apply_existing'] ?? false) {
                MediaProject::where('status', 'completed')->whereDoesntHave('previews', fn ($q) => $q->whereIn('status', ['queued', 'running']))->each(function (MediaProject $project) use ($data): void {
                    $project->update(['storage_policy' => $data['policy']]);
                    app(RetentionCleanup::class)->published($project, false);
                });
            }
        });
    }
}
