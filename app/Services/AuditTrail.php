<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AuditTrail
{
    public const SOURCES = [
        'account' => ['customer_account_events', 'type', 'user_id', 'accounts', 'user'],
        'package' => ['package_events', 'type', 'package_id', 'packages', 'package'],
        'payment' => ['payment_events', 'action', 'payment_transaction_id', 'payments', 'payment'],
        'payment_settings' => ['payment_configuration_events', 'action', 'payment_method_id', 'settings', 'method'],
        'general' => ['platform_setting_events', 'action', 'revision', 'settings', 'general'],
        'provider_check' => ['media_provider_checks', 'capability', 'id', 'settings', 'providers'],
        'storage_check' => ['media_storage_checks', 'success', 'id', 'settings', 'storage'],
        'usage' => ['usage_events', 'kind', 'usage_record_id', 'accounts', 'usage'],
        'job' => ['media_task_actions', 'action', 'media_task_id', 'operations', 'job'],
    ];

    public function modelCreated(Model $model): void
    {
        foreach (self::SOURCES as $source => $config) {
            if ($config[0] === $model->getTable()) {
                $this->capture($source, $model->getKey());

                return;
            }
        }
    }

    public function capture(string $source, int|string $id, bool $historical = false): void
    {
        [$table, $actionField, $targetField, $category, $type] = self::SOURCES[$source];
        $row = DB::table($table)->find($id);
        if (! $row) {
            return;
        }
        if (in_array($source, ['provider_check', 'storage_check'], true)) {
            $success = $source === 'provider_check' ? $row->status === 'success' : (bool) $row->success;
            $this->record($source, (string) $id, 'settings', $type.'.connection_test', $success ? 'success' : 'failed', $row->created_by ?? $row->user_id ?? null,
                $type, null, $type === 'providers' ? 'API Keys & Models' : 'Storage & Retention', [], false, $row->checked_at, $historical);

            return;
        }
        if ($source === 'usage' && $row->kind !== 'adjusted') {
            return;
        }
        $metadata = json_decode($row->metadata ?? '{}', true) ?: [];
        $action = $row->$actionField;
        if ($source === 'account' && str_starts_with($action, 'subscription_')) {
            $category = 'packages';
        }
        $targetId = $row->$targetField ?? null;
        $target = match ($type) {
            'user' => DB::table('users')->where('id', $targetId)->value('email'),
            'package' => DB::table('packages')->where('id', $targetId)->value('name'),
            'payment' => 'PYT-'.str_pad((string) $targetId, 7, '0', STR_PAD_LEFT),
            'usage' => 'USG-'.str_pad((string) $targetId, 7, '0', STR_PAD_LEFT),
            'method' => DB::table('payment_methods')->where('id', $targetId)->value('name'),
            'job' => 'J-'.str_pad((string) $targetId, 5, '0', STR_PAD_LEFT),
            default => 'General Settings',
        };
        $failed = (str_ends_with($action, '_failed') || str_ends_with($action, '_error')) || ($action === 'welcome_email' && ($metadata['delivery'] ?? '') === 'failed');
        $details = $this->safeDetails($metadata);
        if ($source === 'general') {
            $details['fields'] = array_values(array_filter(json_decode($row->fields, true) ?: [], fn ($field) => is_string($field) && preg_match('/^[a-z_]+$/', $field)));
            $details['revision'] = $row->revision;
        }
        if ($source === 'usage') {
            $details['reason'] = $this->text($row->reason);
            foreach (json_decode($row->changes, true) ?: [] as $key => $number) {
                if (in_array($key, ['seconds', 'videos', 'returned_seconds', 'returned_videos'], true) && is_numeric($number)) {
                    $details[$key] = (int) $number;
                }
            }
        }
        if ($source === 'job') {
            $details['reason'] = $this->text($row->reason);
        }
        if ($source === 'package' && isset($metadata['version'])) {
            $versions = DB::table('package_versions')->where('package_id', $targetId)->whereIn('number', [$metadata['previous_version'] ?? 0, $metadata['version']])->get();
            foreach (['previous_version' => 'before', 'version' => 'after'] as $key => $side) {
                $version = $versions->firstWhere('number', $metadata[$key] ?? 0);
                if ($version) {
                    $details[$side] = $this->safeDefinition(json_decode($version->definition, true) ?: []);
                }
            }
        }
        $this->record($source, (string) $id, $category, $source.'.'.$action, $failed ? 'failed' : 'success',
            $row->actor_id ?? null, $type, (string) $targetId, $target ?: ucfirst($type).' #'.$targetId, $details,
            ($source === 'general' && $action === 'configuration_saved') || in_array($source, ['payment_settings', 'usage'], true) || preg_match('/password|suspend|archive|revoke|end|refund|approve/', $action) === 1,
            $row->created_at, $historical);
    }

    public function settings(string $key, ?int $actorId, string $action = 'configuration_saved', string $result = 'success'): void
    {
        $this->record('settings_request', (string) Str::uuid(), 'settings', $key.'.'.$action, $result, $actorId, $key, null,
            match ($key) {
                'providers' => 'API Keys & Models', 'storage' => 'Storage & Retention', default => 'System Project Defaults'
            },
            ['fields' => ['configuration'], 'notice' => 'Configuration updated; credentials are never included.'], $action === 'configuration_saved');
    }

    public function security(string $action, ?User $user = null, string $guard = 'web', string $result = 'success'): void
    {
        $this->record('security', (string) Str::uuid(), 'security', $action, $result, $user?->id, 'authentication', null,
            $guard === 'admin' ? 'Administrator sign-in' : 'Customer sign-in', ['guard' => $guard], $action === 'password_reset');
    }

    public function record(string $source, string $sourceId, string $category, string $action, string $result, ?int $actorId,
        string $targetType, ?string $targetId, string $targetLabel, array $details = [], bool $sensitive = false,
        ?string $occurredAt = null, bool $historical = false): void
    {
        $actor = $actorId ? User::find($actorId) : null;
        $request = $historical || ! app()->bound('request') || ! request()->hasSession() ? null : request();
        $ip = $request?->ip();
        $masked = $ip && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? preg_replace('/\\.\\d+$/', '.*', $ip)
            : ($ip && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? implode(':', array_slice(explode(':', $ip), 0, 3)).':…' : null);
        $agent = (string) $request?->userAgent();
        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge', str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Chrome/') => 'Chrome', str_contains($agent, 'Safari/') => 'Safari', default => null,
        };
        if ($request && ! $request->attributes->has('audit_request_id')) {
            $request->attributes->set('audit_request_id', (string) Str::uuid());
        }
        $inserted = DB::table('audit_events')->insertOrIgnore([
            'source' => $source, 'source_id' => $sourceId, 'category' => $category, 'action' => $action, 'result' => $result,
            'sensitive' => $sensitive, 'actor_id' => $actorId, 'actor_kind' => $actor ? ($actor->is_admin ? 'admin' : 'customer') : ($source === 'security' ? 'unknown' : 'system'),
            'actor_name' => $actor ? mb_substr($actor->name, 0, 120) : ($source === 'security' ? 'Unknown' : 'System'),
            'actor_email' => $actor?->email, 'target_type' => $targetType, 'target_id' => $targetId,
            'target_label' => mb_substr($targetLabel, 0, 254), 'details' => json_encode($details, JSON_THROW_ON_ERROR),
            'ip_address' => $masked, 'browser' => $browser, 'request_id' => $request?->attributes->get('audit_request_id'),
            'occurred_at' => $occurredAt ?? now(), 'recorded_at' => now(),
        ]);
        if ($inserted && ! $historical) {
            app(AdminNotifications::class)->audit(AuditEvent::where('source', $source)->where('source_id', $sourceId)->firstOrFail());
        }
    }

    private function text(mixed $value): string
    {
        $text = is_scalar($value) ? mb_substr((string) $value, 0, 1000) : '';

        return preg_replace('/(?:AIza[\\w-]{20,}|cfat_[\\w-]+|Bearer\\s+\\S+|(?:password|secret|token|api[_ -]?key)\\s*[:=]\\s*\\S+)/i', '[redacted]', $text);
    }

    private function safeDefinition(array $value): array
    {
        $safe = [];
        foreach (['name', 'email', 'preferred_locale', 'code', 'type', 'currency', 'price', 'validity_days', 'included_minutes', 'video_limit', 'storage_gib', 'max_video_seconds', 'max_upload_mib', 'concurrency', 'queue_limit', 'retention_days'] as $key) {
            if (array_key_exists($key, $value) && (is_scalar($value[$key]) || $value[$key] === null)) {
                $safe[$key] = $value[$key] === null ? null : $this->text($value[$key]);
            }
        }
        foreach ($value['features'] ?? [] as $key => $enabled) {
            if (in_array($key, PackageCatalog::FEATURES, true)) {
                $safe['feature.'.$key] = (bool) $enabled;
            }
        }

        return $safe;
    }

    private function safeDetails(array $metadata): array
    {
        $safe = [];
        foreach (['reason', 'from', 'to', 'status', 'version', 'previous_version', 'purpose', 'assignment_id', 'payment_id', 'refund_id', 'refund_status', 'access_action', 'amount_minor', 'starts_at', 'ends_at', 'delivery', 'source'] as $key) {
            if (isset($metadata[$key]) && is_scalar($metadata[$key])) {
                $safe[$key] = $this->text($metadata[$key]);
            }
        }
        $safe['fields'] = array_values(array_filter($metadata['fields'] ?? [], fn ($key) => is_string($key) && preg_match('/^[a-z_.]+$/', $key)));
        foreach (['before', 'after'] as $side) {
            $value = $metadata[$side] ?? null;
            if (is_array($value)) {
                $safe[$side] = $this->safeDefinition($value);
                foreach (['starts_at', 'ends_at', 'package_id', 'version', 'status'] as $key) {
                    if (isset($value[$key]) && is_scalar($value[$key])) {
                        $safe[$side][$key] = $this->text($value[$key]);
                    }
                }
            } elseif (is_scalar($value)) {
                $safe[$side] = ['status' => $this->text($value)];
            }
            $package = $metadata['package_change'][$side] ?? null;
            if (is_array($package)) {
                foreach (['package_id', 'version', 'starts_at', 'ends_at'] as $key) {
                    if (isset($package[$key]) && is_scalar($package[$key])) {
                        $safe[$side]['package.'.$key] = $this->text($package[$key]);
                    }
                }
            }
        }

        return $safe;
    }
}
