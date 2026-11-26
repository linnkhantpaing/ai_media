<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Admin-entered prices used to estimate costs: Gemini prices in USD per 1M tokens for each
 * model, R2 storage in USD per GB-month, and the USD/THB to MMK exchange rates. Costs are
 * always computed from the current prices, so entering a price later also fixes past days.
 */
class AiCostSettings
{
    public const PRICE_FIELDS = ['input', 'audio_input', 'cached', 'output'];

    public function current(): array
    {
        $row = DB::table('ai_cost_settings')->orderBy('id')->first();
        $stored = $row ? json_decode((string) $row->configuration, true) : [];
        $stored = is_array($stored) ? $stored : [];

        return ['revision' => (int) ($row->revision ?? 0), 'models' => is_array($stored['models'] ?? null) ? $stored['models'] : [],
            'storage_gb_month' => $stored['storage_gb_month'] ?? '0.015', 'usd_mmk' => $stored['usd_mmk'] ?? null, 'thb_mmk' => $stored['thb_mmk'] ?? null,
            'updated_at' => $row->updated_at ?? null];
    }

    public function save(User $actor, array $data): array
    {
        abort_unless($actor->is_admin, 403);

        return DB::transaction(function () use ($actor, $data): array {
            $row = DB::table('ai_cost_settings')->orderBy('id')->lockForUpdate()->first();
            if ((int) ($row->revision ?? 0) !== (int) $data['revision']) {
                throw ValidationException::withMessages(['revision' => 'Prices were changed by someone else. Reload and try again.']);
            }
            $models = [];
            foreach ($data['models'] ?? [] as $model => $prices) {
                $models[(string) $model] = array_map(fn ($value) => $value === null || $value === '' ? null : $this->decimal($value), array_intersect_key((array) $prices, array_flip(self::PRICE_FIELDS)));
            }
            $configuration = ['models' => $models, 'storage_gb_month' => $this->decimal($data['storage_gb_month'] ?? '0'),
                'usd_mmk' => filled($data['usd_mmk'] ?? null) ? $this->decimal($data['usd_mmk']) : null, 'thb_mmk' => filled($data['thb_mmk'] ?? null) ? $this->decimal($data['thb_mmk']) : null];
            $values = ['configuration' => json_encode($configuration, JSON_THROW_ON_ERROR), 'updated_by' => $actor->id, 'updated_at' => now()];
            $row ? DB::table('ai_cost_settings')->where('id', $row->id)->update([...$values, 'revision' => $row->revision + 1])
                : DB::table('ai_cost_settings')->insert([...$values, 'revision' => 1, 'created_at' => now()]);

            return $this->current();
        });
    }

    /** Estimated USD cost of a token bundle for one model, or null when that model has no input/output price yet. */
    public function usd(string $model, array $tokens, ?array $settings = null): ?float
    {
        $prices = ($settings ?? $this->current())['models'][$model] ?? null;
        if (! is_array($prices) || ($prices['input'] ?? null) === null || ($prices['output'] ?? null) === null) {
            return null;
        }
        $input = (float) $prices['input'];
        $audio = min((int) $tokens['audio_input_tokens'], (int) $tokens['input_tokens']);
        $cached = min((int) $tokens['cached_tokens'], max(0, (int) $tokens['input_tokens'] - $audio));
        $text = max(0, (int) $tokens['input_tokens'] - $audio - $cached);

        return ($text * $input + $audio * (float) ($prices['audio_input'] ?? $input) + $cached * (float) ($prices['cached'] ?? $input)
            + ((int) $tokens['output_tokens'] + (int) $tokens['thought_tokens']) * (float) $prices['output']) / 1_000_000;
    }

    public function toMmk(?float $amount, string $currency, ?array $settings = null): ?float
    {
        if ($amount === null) {
            return null;
        }
        $settings ??= $this->current();
        $rate = match (strtoupper($currency)) {
            'MMK' => 1.0, 'USD' => $settings['usd_mmk'] !== null ? (float) $settings['usd_mmk'] : null, 'THB' => $settings['thb_mmk'] !== null ? (float) $settings['thb_mmk'] : null, default => null,
        };

        return $rate === null ? null : $amount * $rate;
    }

    private function decimal(mixed $value): string
    {
        if (! is_numeric($value) || (float) $value < 0 || (float) $value > 1_000_000_000) {
            throw ValidationException::withMessages(['prices' => 'Enter prices and rates as positive numbers.']);
        }

        return rtrim(rtrim(number_format((float) $value, 6, '.', ''), '0'), '.') ?: '0';
    }
}
