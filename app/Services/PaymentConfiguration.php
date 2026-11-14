<?php

namespace App\Services;

use App\Models\PackageVersion;
use App\Models\PaymentConfigurationEvent;
use App\Models\PaymentMethod;
use App\Models\PaymentMethodVersion;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PaymentConfiguration
{
    public const CURRENCIES = ['MMK', 'THB', 'USD'];

    public function defaults(): array
    {
        return [
            'currencies' => array_fill_keys(self::CURRENCIES, ['enabled' => false, 'default_id' => null]),
            'rules' => ['evidence_required' => true, 'reference_required' => false, 'sender_required' => false, 'transfer_date_required' => false, 'expiry_hours' => 24, 'instructions' => ['my' => '', 'en' => '', 'th' => '']],
        ];
    }

    public function settings(): array
    {
        $row = DB::table('payment_settings')->where('id', 1)->first();

        return [
            'revision' => (int) ($row?->revision ?? 0),
            'currencies' => $row ? json_decode($row->currencies, true, flags: JSON_THROW_ON_ERROR) : $this->defaults()['currencies'],
            'rules' => $row ? json_decode($row->rules, true, flags: JSON_THROW_ON_ERROR) : $this->defaults()['rules'],
            'saved_at' => $row?->updated_at,
        ];
    }

    public function ready(PaymentMethod $method): bool
    {
        return filled($method->details['holder'] ?? null) && filled($method->details['identifier'] ?? null)
            && ($method->type !== 'other' || collect($method->instructions)->contains(fn ($value) => filled($value)));
    }

    public function resource(PaymentMethod $method, ?array $settings = null, bool $full = false): array
    {
        $settings ??= $this->settings();
        $identifier = (string) ($method->details['identifier'] ?? '');
        $masked = $identifier === '' ? '' : str_repeat('*', max(4, min(10, mb_strlen($identifier) - 4))).(mb_strlen($identifier) > 4 ? mb_substr($identifier, -4) : '');
        $currency = $settings['currencies'][$method->currency];
        $result = [
            'id' => $method->id, 'name' => $method->name, 'type' => $method->type, 'currency' => $method->currency, 'provider' => $method->provider,
            'status' => $method->status, 'revision' => $method->revision, 'sort_order' => $method->sort_order,
            'holder' => $method->details['holder'] ?? '', 'masked_identifier' => $masked,
            'ready' => $this->ready($method), 'is_default' => (int) ($currency['default_id'] ?? 0) === $method->id,
            'usable' => $method->status === 'active' && $currency['enabled'] && $this->ready($method),
            'duplicate' => filled($method->identity_hash) && PaymentMethod::where('identity_hash', $method->identity_hash)->whereKeyNot($method->id)->where('status', '!=', 'archived')->exists(),
            'qr_url' => $method->qr_path ? route('admin.settings.payments.qr', ['method' => $method->id, 'version' => $method->revision], absolute: false) : null,
            'updated_at' => $method->updated_at?->toIso8601String(),
            'show_url' => route('admin.settings.payments.method', $method->id, absolute: false),
            'action_url' => route('admin.settings.payments.action', $method->id, absolute: false),
        ];
        if ($full) {
            $result += ['details' => $method->details, 'instructions' => $method->instructions, 'internal_label' => $method->internal_label ?? ''];
        }

        return $result;
    }

    public function details(PaymentMethod $method): array
    {
        $events = PaymentConfigurationEvent::with('actor:id,name')->where(fn ($query) => $query->where('payment_method_id', $method->id)->orWhereJsonContains('metadata->affected_ids', $method->id))
            ->latest('id')->limit(50)->get()->map(fn ($event) => ['id' => $event->id, 'action' => $event->action, 'actor' => $event->actor?->name, 'metadata' => $event->metadata, 'created_at' => $event->created_at->toIso8601String()])->all();

        return ['method' => $this->resource($method, full: true), 'history' => $events, 'config_revision' => $this->settings()['revision']];
    }

    public function saveMethod(User $actor, array $data, ?int $id = null): array
    {
        $file = $data['qr_file'] ?? null;
        unset($data['qr_file']);
        $data['qr_digest'] = $file instanceof UploadedFile ? hash_file('sha256', $file->getRealPath()) : null;
        $newFile = null;
        try {
            return $this->mutate($actor, $data, $id ? 'update' : 'create', $id, function (array &$settings, array &$affected) use ($actor, $data, $id, $file, &$newFile): array {
                $method = $id ? PaymentMethod::whereKey($id)->lockForUpdate()->firstOrFail() : new PaymentMethod;
                if ($id) {
                    abort_unless($method->revision === (int) $data['revision'] && $method->status !== 'archived', 409, 'This method changed. Refresh before editing.');
                }
                $before = $id ? $this->audit($method) : null;
                $oldCurrency = $id ? $method->currency : null;
                $wasDefault = $id && (int) ($settings['currencies'][$oldCurrency]['default_id'] ?? 0) === $id;
                $details = array_map(fn ($value) => trim((string) $value), $data['details']);
                $instructions = array_map(fn ($value) => trim((string) $value), $data['instructions']);
                $sensitive = $id && ($method->currency !== $data['currency'] || $method->details !== $details || $file || $data['remove_qr']);
                $switchDefault = (bool) $data['make_default'] && filled($settings['currencies'][$data['currency']]['default_id']) && (int) $settings['currencies'][$data['currency']]['default_id'] !== $id;
                $this->require(! ($sensitive || $switchDefault || ($id && $method->status !== $data['status']) || ($wasDefault && ! $data['make_default'])) || filled($data['reason'] ?? null), 'reason', 'Provide a reason for changing receiving details, status or the default method.');
                $this->require(! ($file && $data['remove_qr']), 'qr_file', 'Choose either a new QR image or remove the existing image.');
                if ($wasDefault && ($oldCurrency !== $data['currency'] || $data['status'] !== 'active' || ! $data['make_default'])) {
                    $this->replaceDefault($settings, $oldCurrency, $id, $data, $actor, $affected);
                }
                $method->forceFill([
                    'name' => $data['name'], 'type' => $data['type'], 'currency' => $data['currency'], 'provider' => $data['provider'],
                    'internal_label' => $data['internal_label'] ?? null, 'status' => $data['status'], 'sort_order' => (int) $data['sort_order'],
                    'details' => $details, 'instructions' => $instructions,
                    'identity_hash' => filled($details['identifier']) ? hash_hmac('sha256', $data['currency'].'|'.mb_strtolower(trim($data['provider'])).'|'.mb_strtolower($details['identifier']), (string) config('app.key')) : null,
                ]);
                if ($method->status === 'active') {
                    $this->require($this->ready($method), 'details.identifier', 'Provide the receiving account holder, identifier and any required instructions before activation.');
                    if ($data['enable_currency']) {
                        $settings['currencies'][$method->currency]['enabled'] = true;
                    }
                    $this->require($settings['currencies'][$method->currency]['enabled'], 'enable_currency', 'Enable this currency or save the method as inactive.');
                }
                if ($data['remove_qr']) {
                    $method->qr_path = null;
                } elseif ($file instanceof UploadedFile) {
                    $newFile = 'payment-qr/'.Str::uuid().'.'.$file->extension();
                    $stream = fopen($file->getRealPath(), 'rb');
                    try {
                        abort_unless(Storage::disk('local')->put($newFile, $stream), 503, 'Unable to store the QR image.');
                    } finally {
                        if (is_resource($stream)) {
                            fclose($stream);
                        }
                    }
                    $method->qr_path = $newFile;
                }
                $method->save();
                if ($data['make_default']) {
                    if ($switchDefault) {
                        $affected[] = (int) $settings['currencies'][$method->currency]['default_id'];
                    }
                    $this->require($method->status === 'active' && $this->ready($method), 'make_default', 'A default method must be complete and active.');
                    $settings['currencies'][$method->currency]['default_id'] = $method->id;
                }
                $this->version($method, $actor);
                $affected[] = $method->id;

                return ['method_id' => $method->id, 'before' => $before, 'after' => $this->audit($method), 'reason' => $data['reason'] ?? null];
            });
        } catch (\Throwable $exception) {
            if ($newFile) {
                Storage::disk('local')->delete($newFile);
            }
            throw $exception;
        }
    }

    public function methodAction(User $actor, int $id, array $data): array
    {
        return $this->mutate($actor, $data, $data['action'], $id, function (array &$settings, array &$affected) use ($actor, $id, $data): array {
            $method = PaymentMethod::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless($method->revision === (int) $data['revision'], 409, 'This method changed. Refresh and review it.');
            $before = $this->audit($method);
            $action = $data['action'];
            $allowed = match ($action) {
                'default' => $method->status === 'active', 'activate' => $method->status === 'inactive',
                'deactivate' => $method->status === 'active', 'archive' => $method->status !== 'archived', 'restore' => $method->status === 'archived', default => false,
            };
            abort_unless($allowed, 409, 'This action is no longer available.');
            if (in_array($action, ['deactivate', 'archive'], true) && (int) ($settings['currencies'][$method->currency]['default_id'] ?? 0) === $id) {
                $this->replaceDefault($settings, $method->currency, $id, $data, $actor, $affected);
            }
            if (in_array($action, ['default', 'activate'], true)) {
                $this->require($this->ready($method), 'method', 'Complete the receiving details before activation.');
                if ($data['enable_currency'] ?? false) {
                    $settings['currencies'][$method->currency]['enabled'] = true;
                }
                $this->require($settings['currencies'][$method->currency]['enabled'], 'enable_currency', 'Enable the receiving currency.');
                $method->status = 'active';
                if ($action === 'default' || ($data['make_default'] ?? false)) {
                    if (filled($settings['currencies'][$method->currency]['default_id'])) {
                        $affected[] = (int) $settings['currencies'][$method->currency]['default_id'];
                    }
                    $settings['currencies'][$method->currency]['default_id'] = $id;
                }
            } else {
                $method->status = $action === 'archive' ? 'archived' : 'inactive';
            }
            $this->version($method, $actor);
            $affected[] = $id;

            return ['method_id' => $id, 'before' => $before, 'after' => $this->audit($method), 'reason' => $data['reason']];
        });
    }

    public function saveCurrencies(User $actor, array $data): array
    {
        return $this->mutate($actor, $data, 'currencies', null, function (array &$settings, array &$affected) use ($actor, $data): array {
            $before = $settings['currencies'];
            foreach (self::CURRENCIES as $code) {
                $next = $data['currencies'][$code];
                $previous = $settings['currencies'][$code];
                if ($previous['enabled'] !== (bool) $next['enabled'] || (int) ($previous['default_id'] ?? 0) !== (int) ($next['default_id'] ?? 0)) {
                    foreach ([$previous['default_id'], $next['default_id']] as $changedId) {
                        if ($changedId) {
                            $affected[] = (int) $changedId;
                        }
                    }
                }
                if (! $next['enabled']) {
                    $this->disableCurrency($settings, $code, $actor, $affected);

                    continue;
                }
                $method = PaymentMethod::whereKey($next['default_id'])->lockForUpdate()->first();
                $this->require($method && $method->currency === $code && $method->status !== 'archived' && $this->ready($method), 'currencies.'.$code.'.default_id', 'Choose a complete, matching receiving method.');
                if ($method->status !== 'active') {
                    $method->status = 'active';
                    $this->version($method, $actor);
                    $affected[] = $method->id;
                }
                $settings['currencies'][$code] = ['enabled' => true, 'default_id' => $method->id];
            }

            return ['before' => $before, 'after' => $settings['currencies'], 'reason' => $data['reason']];
        });
    }

    public function saveRules(User $actor, array $data): array
    {
        return $this->mutate($actor, $data, 'rules', null, function (array &$settings, array &$affected) use ($data): array {
            $before = $settings['rules'];
            $settings['rules'] = [...array_map(fn ($value) => (bool) $value, array_intersect_key($data['rules'], array_flip(['evidence_required', 'reference_required', 'sender_required', 'transfer_date_required']))), 'expiry_hours' => (int) $data['rules']['expiry_hours'], 'instructions' => array_map(fn ($value) => trim((string) $value), $data['rules']['instructions'])];

            return ['before' => $before, 'after' => $settings['rules']];
        });
    }

    public function capture(string $currency, ?int $methodId = null): array
    {
        $this->require(in_array($currency, self::CURRENCIES, true), 'currency', 'Choose MMK, THB or USD.');
        $settings = $this->settings();
        $this->require($settings['currencies'][$currency]['enabled'], 'currency', 'This currency is not available.');
        $method = PaymentMethod::find($methodId ?? $settings['currencies'][$currency]['default_id']);
        $this->require($method && $method->currency === $currency && $method->status === 'active' && $this->ready($method), 'method', 'Choose an active method matching this currency.');
        $version = PaymentMethodVersion::where('payment_method_id', $method->id)->where('number', $method->revision)->firstOrFail();
        $definition = $version->definition;
        unset($definition['internal_label']);

        return ['config_revision' => $settings['revision'], 'method_id' => $method->id, 'method_version_id' => $version->id, 'currency' => $currency, 'verification' => 'manual', 'receiving' => $definition, 'rules' => $settings['rules']];
    }

    public function availableCurrencies(PackageVersion $version): array
    {
        $currency = $version->definition['currency'] ?? null;
        if (! in_array($currency, self::CURRENCIES, true) || (int) ($version->definition['price_minor'] ?? 0) <= 0) {
            return [];
        }
        try {
            $this->capture($currency);
        } catch (ValidationException) {
            return [];
        }

        return [$currency];
    }

    private function mutate(User $actor, array $data, string $action, ?int $methodId, \Closure $callback): array
    {
        $fingerprint = hash_hmac('sha256', json_encode([$actor->id, $methodId, $action, $data], JSON_THROW_ON_ERROR), (string) config('app.key'));

        return Cache::lock('payment-configuration', 30)->block(10, fn (): array => DB::transaction(function () use ($actor, $data, $action, $methodId, $fingerprint, $callback): array {
            $defaults = $this->defaults();
            DB::table('payment_settings')->insertOrIgnore(['id' => 1, 'revision' => 0, 'currencies' => json_encode($defaults['currencies'], JSON_THROW_ON_ERROR), 'rules' => json_encode($defaults['rules'], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('payment_settings')->where('id', 1)->lockForUpdate()->first();
            $prior = PaymentConfigurationEvent::where('operation_key', $data['request_id'])->first();
            if ($prior) {
                abort_unless(hash_equals($prior->fingerprint, $fingerprint), 409, 'This request has already been used.');

                return ['replayed' => true, 'id' => $prior->payment_method_id];
            }
            $settings = $this->settings();
            abort_unless($settings['revision'] === (int) $data['config_revision'], 409, 'Payment configuration changed. Refresh and review before saving.');
            $affected = [];
            $metadata = $callback($settings, $affected);
            $this->validateSettings($settings);
            $revision = $settings['revision'] + 1;
            DB::table('payment_settings')->where('id', 1)->update(['currencies' => json_encode($settings['currencies'], JSON_THROW_ON_ERROR), 'rules' => json_encode($settings['rules'], JSON_THROW_ON_ERROR), 'revision' => $revision, 'updated_by' => $actor->id, 'updated_at' => now()]);
            PaymentConfigurationEvent::create([
                'payment_method_id' => $metadata['method_id'] ?? $methodId, 'actor_id' => $actor->id, 'action' => $action, 'revision' => $revision,
                'metadata' => [...$metadata, 'affected_ids' => array_values(array_unique($affected))], 'operation_key' => $data['request_id'], 'fingerprint' => $fingerprint,
            ]);

            return ['replayed' => false, 'id' => $metadata['method_id'] ?? $methodId, 'revision' => $revision];
        }));
    }

    private function replaceDefault(array &$settings, string $currency, int $id, array $data, User $actor, array &$affected): void
    {
        $replacement = $data['replacement_id'] ?? null;
        $disable = (bool) ($data['disable_currency'] ?? false);
        $this->require(! ($replacement && $disable), 'replacement_id', 'Choose a replacement or disable the currency.');
        if ($disable) {
            $this->disableCurrency($settings, $currency, $actor, $affected, $id);

            return;
        }
        $method = $replacement ? PaymentMethod::whereKey($replacement)->lockForUpdate()->first() : null;
        $this->require($method && $method->id !== $id && $method->currency === $currency && $method->status === 'active' && $this->ready($method), 'replacement_id', 'Choose another active method for this currency, or explicitly disable the currency.');
        $settings['currencies'][$currency]['default_id'] = $method->id;
    }

    private function disableCurrency(array &$settings, string $currency, User $actor, array &$affected, ?int $except = null): void
    {
        foreach (PaymentMethod::where('currency', $currency)->where('status', 'active')->when($except, fn ($query) => $query->whereKeyNot($except))->lockForUpdate()->get() as $method) {
            $method->status = 'inactive';
            $this->version($method, $actor);
            $affected[] = $method->id;
        }
        $settings['currencies'][$currency] = ['enabled' => false, 'default_id' => null];
    }

    private function validateSettings(array $settings): void
    {
        foreach (self::CURRENCIES as $code) {
            $currency = $settings['currencies'][$code];
            if ($currency['enabled']) {
                $default = PaymentMethod::find($currency['default_id']);
                $this->require($default && $default->currency === $code && $default->status === 'active' && $this->ready($default), 'currencies.'.$code.'.default_id', 'An enabled currency needs a complete, active default method.');
            } else {
                $this->require(! PaymentMethod::where('currency', $code)->where('status', 'active')->exists(), 'currency', 'Disable active methods before disabling their currency.');
            }
        }
    }

    private function version(PaymentMethod $method, User $actor): void
    {
        $method->revision = ((int) $method->revision) + 1;
        $method->updated_by = $actor->id;
        $method->save();
        PaymentMethodVersion::create(['payment_method_id' => $method->id, 'number' => $method->revision, 'definition' => [
            'name' => $method->name, 'type' => $method->type, 'provider' => $method->provider, 'currency' => $method->currency, 'status' => $method->status,
            'details' => $method->details, 'instructions' => $method->instructions, 'qr_path' => $method->qr_path, 'internal_label' => $method->internal_label,
        ], 'actor_id' => $actor->id]);
    }

    private function audit(PaymentMethod $method): array
    {
        return ['id' => $method->id, 'name' => $method->name, 'currency' => $method->currency, 'status' => $method->status, 'version' => $method->revision, 'receiving_details_changed' => $method->isDirty('details')];
    }

    private function require(bool $condition, string $field, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
