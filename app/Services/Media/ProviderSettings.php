<?php

namespace App\Services\Media;

use App\Exceptions\MediaFailure;
use App\Services\AuditTrail;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class ProviderSettings
{
    private ?array $active = null;

    public function baseline(): array
    {
        $key = trim((string) config('media.gemini_key'));

        return [
            'enabled' => true,
            'credentials' => $key === '' ? [] : [['id' => 'server', 'label' => 'Server key', 'secret' => $key, 'enabled' => true]],
            'text_models' => [(string) config('media.gemini_model')],
            'speech_model' => (string) config('media.gemini_tts_model'),
            'voice' => 'Kore', 'speech_rate' => 1.0, 'voice_volume' => 1.0,
            'timeout' => (int) config('media.provider_timeout'),
            'api_revision' => (string) config('media.gemini_revision'),
            'auto_processing' => true, 'worker_idle_seconds' => 30,
        ];
    }

    public function revision(): int
    {
        return (int) DB::table('media_provider_settings')->where('id', 1)->value('revision_id');
    }

    public function current(): array
    {
        return ($id = $this->revision()) ? $this->read($id) : $this->baseline();
    }

    public function resolved(): array
    {
        return $this->active ?? $this->current();
    }

    public function ready(?array $configuration = null): bool
    {
        $configuration ??= $this->resolved();

        return $configuration['enabled'] && collect($configuration['credentials'])->contains(fn (array $key): bool => $key['enabled'] && trim($key['secret']) !== '');
    }

    public function fingerprint(array $configuration): string
    {
        return hash_hmac('sha256', json_encode(array_diff_key($configuration, array_flip(['auto_processing', 'worker_idle_seconds'])), JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    private function read(int $id): array
    {
        $row = DB::table('media_provider_revisions')->find($id);
        if (! $row) {
            throw new MediaFailure('provider_configuration_missing');
        }

        return array_replace(['auto_processing' => true, 'worker_idle_seconds' => 30], json_decode(Crypt::decryptString($row->configuration), true, 512, JSON_THROW_ON_ERROR));
    }

    private function insert(array $configuration, ?int $userId = null): int
    {
        return DB::table('media_provider_revisions')->insertGetId([
            'configuration' => Crypt::encryptString(json_encode($configuration, JSON_THROW_ON_ERROR)),
            'fingerprint' => $this->fingerprint($configuration), 'created_by' => $userId, 'created_at' => now(),
        ]);
    }

    public function snapshot(): int
    {
        return DB::transaction(function (): int {
            $head = DB::table('media_provider_settings')->where('id', 1)->lockForUpdate()->first();
            if ($head->revision_id) {
                return $head->revision_id;
            }
            $configuration = $this->baseline();

            return DB::table('media_provider_revisions')->where('fingerprint', $this->fingerprint($configuration))->value('id') ?? $this->insert($configuration);
        });
    }

    public function withRevision(?int $id, callable $action): mixed
    {
        return $this->withConfiguration($id ? $this->read($id) : $this->current(), $action);
    }

    public function withConfiguration(array $configuration, callable $action): mixed
    {
        $previous = $this->active;
        $this->active = $configuration;
        try {
            return $action();
        } finally {
            $this->active = $previous;
        }
    }

    public function draft(array $data, bool $validateModels = true): array
    {
        if ((int) $data['revision'] !== $this->revision()) {
            abort(409, 'settings_conflict');
        }
        $current = $this->current();
        $existing = collect($current['credentials'])->keyBy('id');
        $keys = [];
        foreach ($data['credentials'] as $index => $key) {
            $old = $existing->get($key['id'] ?? '');
            $secret = trim((string) ($key['secret'] ?? ''));
            if ($secret === '') {
                $secret = $old['secret'] ?? '';
            }
            if ($secret === '') {
                throw ValidationException::withMessages(['credentials.'.$index.'.secret' => 'A key is required for a new credential.']);
            }
            $keys[] = ['id' => $old['id'] ?? $key['id'], 'label' => trim($key['label']), 'secret' => $secret, 'enabled' => (bool) $key['enabled']];
        }
        $result = [
            'enabled' => (bool) $data['enabled'], 'credentials' => $keys,
            'text_models' => array_values($data['text_models']), 'speech_model' => $data['speech_model'],
            'voice' => $data['voice'], 'speech_rate' => (float) $data['speech_rate'], 'voice_volume' => (float) $data['voice_volume'],
            'timeout' => (int) $data['timeout'], 'api_revision' => $current['api_revision'],
            'auto_processing' => (bool) ($data['auto_processing'] ?? $current['auto_processing']),
            'worker_idle_seconds' => (int) ($data['worker_idle_seconds'] ?? $current['worker_idle_seconds']),
        ];
        if ($result['enabled'] && ! $this->ready($result)) {
            throw ValidationException::withMessages(['credentials' => 'Enable at least one credential, or disable the provider.']);
        }
        if (! $validateModels) {
            return $result;
        }
        $known = collect($this->catalog($result))->keyBy('id');
        foreach ($result['text_models'] as $model) {
            if (! in_array($model, $current['text_models'], true) && ($known->get($model)['kind'] ?? null) !== 'text') {
                throw ValidationException::withMessages(['text_models' => 'Refresh the model list before choosing this text model.']);
            }
        }
        if ($result['speech_model'] !== $current['speech_model'] && ($known->get($result['speech_model'])['kind'] ?? null) !== 'speech') {
            throw ValidationException::withMessages(['speech_model' => 'Refresh the model list before choosing this speech model.']);
        }

        return $result;
    }

    public function save(array $configuration, int $expected, int $userId): int
    {
        return DB::transaction(function () use ($configuration, $expected, $userId): int {
            $head = DB::table('media_provider_settings')->where('id', 1)->lockForUpdate()->first();
            abort_if((int) $head->revision_id !== $expected, 409, 'settings_conflict');
            if (! $head->revision_id) {
                $baseline = $this->snapshot();
                foreach (['media_runs', 'media_previews'] as $table) {
                    DB::table($table)->whereNull('provider_revision_id')->update(['provider_revision_id' => $baseline]);
                }
            }
            $id = $this->insert($configuration, $userId);
            DB::table('media_provider_settings')->where('id', 1)->update(['revision_id' => $id]);
            app(AuditTrail::class)->record('provider_revision', (string) $id, 'settings', 'providers.configuration_saved', 'success', $userId, 'providers', null, 'API Keys & Models', ['revision' => $id], true);

            return $id;
        });
    }

    public function catalog(array $configuration): array
    {
        return Cache::get('provider-models:'.$this->credentialFingerprint($configuration), []);
    }

    private function credentialFingerprint(array $configuration): string
    {
        return $this->fingerprint(['credentials' => array_map(fn (array $key): array => ['secret' => $key['secret'], 'enabled' => $key['enabled']], $configuration['credentials'])]);
    }

    public function refreshModels(array $configuration): array
    {
        if (! $this->ready($configuration)) {
            throw new MediaFailure('ai_key_missing');
        }
        $models = [];
        $page = null;
        $pages = 0;
        do {
            $pages++;
            $response = null;
            foreach (array_filter($configuration['credentials'], fn (array $key): bool => $key['enabled']) as $credential) {
                $quotaKey = 'provider-cooldown:'.hash_hmac('sha256', $credential['secret'], (string) config('app.key'));
                $wait = (int) Cache::get($quotaKey, 0) - now()->timestamp;
                if ($wait > 0) {
                    throw new MediaFailure('provider_rate_limit', true, $wait);
                }
                try {
                    $response = Http::connectTimeout(10)->timeout(min(60, $configuration['timeout']))
                        ->withHeaders(['x-goog-api-key' => $credential['secret']])
                        ->get('https://generativelanguage.googleapis.com/v1beta/models', array_filter(['pageSize' => 1000, 'pageToken' => $page]));
                } catch (ConnectionException) {
                    throw new MediaFailure('provider_unavailable', true);
                }
                if (in_array($response->status(), [401, 403], true)) {
                    continue;
                }
                if ($response->status() === 429) {
                    $seconds = max(1, min(900, (int) $response->header('Retry-After') ?: 30));
                    Cache::put($quotaKey, now()->timestamp + $seconds, $seconds);
                    throw new MediaFailure('provider_rate_limit', true, $seconds);
                }
                if (! $response->successful()) {
                    throw new MediaFailure('provider_unavailable');
                }
                break;
            }
            if (! $response || ! $response->successful()) {
                throw new MediaFailure('provider_authentication');
            }
            foreach ($response->json('models', []) as $model) {
                $id = preg_replace('/^models\//', '', (string) ($model['name'] ?? ''));
                if (! preg_match('/^gemini-[a-zA-Z0-9._-]{1,100}$/', $id) || ! in_array('generateContent', $model['supportedGenerationMethods'] ?? [], true) || preg_match('/image|live|robotics|embedding|computer-use|native-audio/', $id)) {
                    continue;
                }
                $models[$id] = ['id' => $id, 'label' => mb_substr((string) ($model['displayName'] ?? $id), 0, 120), 'kind' => str_contains($id, 'tts') ? 'speech' : 'text'];
            }
            $page = $response->json('nextPageToken');
        } while (is_string($page) && $page !== '' && count($models) < 500 && $pages < 5);
        if ($models === []) {
            throw new MediaFailure('provider_response_invalid');
        }
        $models = array_values($models);
        Cache::put('provider-models:'.$this->credentialFingerprint($configuration), $models, now()->addHours(6));

        return $models;
    }

    public function report(array $configuration, string $capability, string $status, ?string $error, int $userId): array
    {
        $report = ['capability' => $capability, 'status' => $status, 'errorCode' => $error, 'checkedAt' => now()->toIso8601String()];
        $auditId = DB::table('media_provider_checks')->insertGetId([
            'fingerprint' => $this->fingerprint($configuration), 'capability' => $capability,
            'status' => $status, 'error_code' => $error, 'created_by' => $userId, 'checked_at' => now(),
        ]);

        app(AuditTrail::class)->capture('provider_check', $auditId);

        return $report;
    }

    public function present(): array
    {
        $configuration = $this->current();
        $id = $this->revision();
        $created = $id ? DB::table('media_provider_revisions')->where('id', $id)->value('created_at') : null;
        $checks = DB::table('media_provider_checks')->where('fingerprint', $this->fingerprint($configuration))->orderByDesc('id')->limit(20)->get()->unique('capability')->map(fn ($check): array => [
            'capability' => $check->capability, 'status' => $check->status, 'errorCode' => $check->error_code, 'checkedAt' => Carbon::parse($check->checked_at)->toIso8601String(),
        ])->values()->all();

        return [
            'revision' => $id, 'source' => $id ? 'workspace' : 'server', 'addedAt' => $created ? Carbon::parse($created)->toIso8601String() : null,
            'enabled' => $configuration['enabled'], 'ready' => $this->ready($configuration),
            'credentials' => array_map(fn (array $key): array => ['id' => $key['id'], 'label' => $key['label'], 'enabled' => $key['enabled'], 'masked' => '••••••••'.substr($key['secret'], -4)], $configuration['credentials']),
            'text_models' => $configuration['text_models'], 'speech_model' => $configuration['speech_model'],
            'voice' => $configuration['voice'], 'speech_rate' => $configuration['speech_rate'], 'voice_volume' => $configuration['voice_volume'],
            'timeout' => $configuration['timeout'], 'apiRevision' => $configuration['api_revision'],
            'auto_processing' => $configuration['auto_processing'], 'worker_idle_seconds' => $configuration['worker_idle_seconds'],
            'models' => $this->catalog($configuration), 'voices' => config('media.voices'), 'checks' => $checks,
        ];
    }
}
