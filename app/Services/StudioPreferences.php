<?php

namespace App\Services;

use App\Models\MediaPreset;
use App\Models\User;
use App\Services\Media\ProjectDefaults;
use App\Support\MediaSettings;
use App\Support\NameDictionary;
use App\Support\StudioTesting;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A customer's personal Recap Studio defaults and saved presets.
 *
 * New projects start from the Admin System Project Defaults, then the Studio's
 * V1 starting values, then the customer's saved preferences, and finally their
 * default preset (or the preset chosen with "Start with preset"). Values that
 * the current package does not include always fall back to the system value.
 */
class StudioPreferences
{
    public const MAX_PRESETS = 12;

    /** Settings a customer can choose as personal defaults or store in a preset. */
    public const KEYS = ['workflow', 'mode', 'narration_lang', 'subtitle_lang', 'target_seconds', 'narration_style', 'voice', 'speech_rate', 'voice_pitch', 'output', 'quality',
        'subtitle_font', 'subtitle_bold', 'subtitle_outline', 'subtitle_shadow', 'subtitle_background', 'subtitle_opacity', 'subtitle_position'];

    /**
     * Studio starting values used before any personal choice. Mirrors the
     * `studioStart` constant in resources/js/lib/studio-defaults.ts.
     */
    public const STARTING = ['workflow' => 'movie', 'mode' => 'dub', 'narration_lang' => 'my', 'subtitle_lang' => 'same', 'target_seconds' => 600, 'narration_style' => 'storyteller',
        'voice_pitch' => 0.0, 'subtitle_font' => 'Padauk', 'subtitle_bold' => false, 'subtitle_outline' => 2.0, 'subtitle_shadow' => 1.0, 'subtitle_background' => false,
        'subtitle_opacity' => 0.5, 'subtitle_position' => 'bottom', 'quality' => 'source'];

    private const SUBTITLE_KEYS = ['subtitle_font', 'subtitle_bold', 'subtitle_outline', 'subtitle_shadow', 'subtitle_background', 'subtitle_opacity', 'subtitle_position'];

    /** @return array<string, list<mixed>> */
    public static function rules(string $prefix = 'settings'): array
    {
        $languages = array_values(array_diff(MediaSettings::LANGUAGES, ['auto']));

        return [
            $prefix => ['required', 'array'],
            $prefix.'.workflow' => ['required', Rule::in(['movie', 'translate', 'subtitles'])],
            $prefix.'.narration_lang' => ['required', Rule::in($languages)],
            $prefix.'.subtitle_lang' => ['required', Rule::in([...$languages, 'same'])],
            $prefix.'.target_seconds' => ['required', 'integer', 'between:30,3600'],
            $prefix.'.narration_style' => ['required', Rule::in(['standard', 'storyteller', 'suspense', 'punchy', 'emotional', 'funny', 'documentary', 'custom'])],
            $prefix.'.voice' => ['required', Rule::in(config('media.voices'))],
            $prefix.'.speech_rate' => ['required', 'numeric', 'between:0.5,2'],
            $prefix.'.voice_pitch' => ['required', 'numeric', 'between:-4,4'],
            $prefix.'.output' => ['required', Rule::in(['burn', 'srt', 'mp3', 'wav'])],
            $prefix.'.quality' => ['required', Rule::in(['source', '720p', '1080p'])],
            $prefix.'.subtitle_font' => ['required', Rule::in(['Padauk', 'Arial', 'Tahoma'])],
            $prefix.'.subtitle_bold' => ['required', 'boolean'],
            $prefix.'.subtitle_outline' => ['required', 'numeric', 'between:0,8'],
            $prefix.'.subtitle_shadow' => ['required', 'numeric', 'between:0,8'],
            $prefix.'.subtitle_background' => ['required', 'boolean'],
            $prefix.'.subtitle_opacity' => ['required', 'numeric', 'between:0,1'],
            $prefix.'.subtitle_position' => ['required', Rule::in(['top', 'middle', 'bottom'])],
        ];
    }

    /**
     * Only preference keys, typed, with the processing mode derived from the workflow.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public function pick(array $settings): array
    {
        $picked = array_intersect_key(MediaSettings::normalize($settings), array_flip(self::KEYS));
        $picked = array_intersect_key($picked, $settings + (isset($settings['workflow']) ? ['mode' => true] : []));
        if (isset($picked['workflow'])) {
            $picked['mode'] = $picked['workflow'] === 'subtitles' ? 'subs' : 'dub';
        }

        return $picked;
    }

    /**
     * The values a customer sees before choosing anything.
     *
     * @return array<string, mixed>
     */
    public function baseline(): array
    {
        $system = array_intersect_key(app(ProjectDefaults::class)->resolved(), array_flip(self::KEYS));
        $baseline = array_replace($system, self::STARTING);
        if (($baseline['mode'] ?? 'dub') === 'subs' && in_array($baseline['output'], ['mp3', 'wav'], true)) {
            $baseline['output'] = 'burn';
        }
        if ($baseline['mode'] === 'dub' && $baseline['output'] === 'srt') {
            $baseline['output'] = 'burn';
        }

        return $baseline;
    }

    /**
     * Personal choices and the default (or requested) preset, without values
     * the customer's current package does not include.
     *
     * @return array<string, mixed>
     */
    public function overrides(User $user, ?MediaPreset $preset = null): array
    {
        $overrides = $this->pick($user->studio_preferences ?? []);
        $defaultId = User::whereKey($user->id)->value('default_preset_id');
        $preset ??= $defaultId ? MediaPreset::where('user_id', $user->id)->find($defaultId) : null;
        if ($preset) {
            $overrides = array_replace($overrides, $this->pick($preset->settings ?? []));
        }
        $features = $this->features($user);
        $baseline = $this->baseline();
        if ($features !== null) {
            foreach ($this->lockedGroups($features) as $keys) {
                foreach ($keys as $key) {
                    unset($overrides[$key]);
                }
            }
            $workflowFeature = ['movie' => 'movie', 'translate' => 'dub', 'subtitles' => 'subs'][$overrides['workflow'] ?? ''] ?? null;
            if ($workflowFeature && ! $features[$workflowFeature]) {
                unset($overrides['workflow'], $overrides['mode']);
            }
            $outputFeature = ['burn' => 'mp4', 'srt' => 'srt', 'mp3' => 'audio', 'wav' => 'audio'][$overrides['output'] ?? ''] ?? null;
            if ($outputFeature && ! $features[$outputFeature]) {
                unset($overrides['output']);
            }
        }
        $mode = $overrides['mode'] ?? $baseline['mode'];
        $output = $overrides['output'] ?? null;
        if ($output && (($mode === 'subs' && in_array($output, ['mp3', 'wav'], true)) || ($mode === 'dub' && $output === 'srt'))) {
            unset($overrides['output']);
        }

        return $overrides;
    }

    /**
     * Package features, or null when no package restricts this customer.
     *
     * @return array<string, bool>|null
     */
    public function features(User $user): ?array
    {
        if (StudioTesting::enabled()) {
            return null;
        }
        $assignment = $user->currentPackageAssignment;

        return $assignment ? PackageCatalog::features($assignment->effectiveSnapshot()['definition'] ?? []) : null;
    }

    /**
     * Setting groups the package does not include, keyed by feature.
     *
     * @param  array<string, bool>  $features
     * @return array<string, list<string>>
     */
    private function lockedGroups(array $features): array
    {
        return array_filter(['subtitle_style' => self::SUBTITLE_KEYS, 'layout' => ['quality']], fn (string $feature): bool => ! $features[$feature], ARRAY_FILTER_USE_KEY);
    }

    /**
     * Combination and package checks for a complete set of preference values.
     *
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $previous  values already saved, kept even if the package changed
     * @return array<string, string>
     */
    public function errors(User $user, array $settings, string $prefix, array $previous = []): array
    {
        $errors = [];
        $mode = $settings['workflow'] === 'subtitles' ? 'subs' : 'dub';
        if ($mode === 'subs' && in_array($settings['output'], ['mp3', 'wav'], true)) {
            $errors[$prefix.'.output'] = 'Audio exports need a narrated recap. Choose MP4 or SRT for subtitles.';
        }
        if ($mode === 'dub' && $settings['output'] === 'srt') {
            $errors[$prefix.'.output'] = 'Narrated recaps export MP4 or audio.';
        }
        $features = $this->features($user);
        if ($features === null) {
            return $errors;
        }
        $message = 'Not included in your plan. Upgrade in Billing & Payments to use it.';
        if (! $features[['movie' => 'movie', 'translate' => 'dub', 'subtitles' => 'subs'][$settings['workflow']]]) {
            $errors[$prefix.'.workflow'] = $message;
        }
        if (! $features[['burn' => 'mp4', 'srt' => 'srt', 'mp3' => 'audio', 'wav' => 'audio'][$settings['output']]]) {
            $errors[$prefix.'.output'] = $message;
        }
        $baseline = $this->baseline();
        foreach ($this->lockedGroups($features) as $keys) {
            foreach ($keys as $key) {
                if (! $this->same($settings[$key], $baseline[$key] ?? null) && ! (array_key_exists($key, $previous) && $this->same($settings[$key], $previous[$key]))) {
                    $errors[$prefix.'.'.$key] = $message;
                }
            }
        }

        return $errors;
    }

    private function same(mixed $value, mixed $expected): bool
    {
        return is_numeric($value) && is_numeric($expected) && ! is_bool($value) && ! is_bool($expected)
            ? abs((float) $value - (float) $expected) < 0.0001
            : $value === $expected;
    }

    /**
     * Complete preference values from a validated request.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public function complete(array $settings): array
    {
        return $this->pick($settings);
    }

    /**
     * Saves the language, personal Studio defaults and Name Dictionary together.
     *
     * @param  array{locale: string, settings: array<string, mixed>, name_dictionary: array<int, array<string, mixed>>}  $data
     */
    public function save(User $user, array $data): void
    {
        $settings = $this->complete($data['settings']);
        $dictionary = NameDictionary::normalize($data['name_dictionary']);
        $errors = $this->errors($user, $settings, 'settings', $this->pick($user->studio_preferences ?? []));
        if ($duplicate = NameDictionary::duplicate($dictionary)) {
            $errors['name_dictionary.'.$duplicate[0].'.original'] = 'This name is already in your dictionary.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $baseline = $this->baseline();
        $changed = array_filter($settings, fn (mixed $value, string $key): bool => ! $this->same($value, $baseline[$key] ?? null), ARRAY_FILTER_USE_BOTH);
        if (isset($changed['workflow']) || isset($changed['mode'])) {
            $changed['workflow'] = $settings['workflow'];
            $changed['mode'] = $settings['mode'];
        }
        DB::transaction(function () use ($user, $data, $changed, $dictionary): void {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $user->forceFill([
                'preferred_locale' => $data['locale'],
                'studio_preferences' => $changed === [] ? null : $changed,
                'recap_name_dictionary' => $dictionary,
            ])->save();
        });
    }

    /** @return list<array<string, mixed>> */
    public function presets(User $user): array
    {
        $default = User::whereKey($user->id)->value('default_preset_id');

        return MediaPreset::where('user_id', $user->id)->orderBy('position')->orderBy('id')->get()
            ->map(fn (MediaPreset $preset): array => [
                'id' => $preset->id, 'name' => $preset->name, 'position' => $preset->position,
                'settings' => array_replace($this->baseline(), $this->pick($preset->settings ?? [])),
                'is_default' => $preset->id === $default,
                'created_at' => $preset->created_at?->toIso8601String(), 'updated_at' => $preset->updated_at?->toIso8601String(),
            ])->all();
    }

    /**
     * Creates or updates a preset owned by the customer.
     *
     * @param  array<string, mixed>  $settings
     */
    public function savePreset(User $user, string $name, array $settings, ?int $id = null): MediaPreset
    {
        $name = trim($name);
        $settings = $this->complete($settings);
        $preset = $id ? MediaPreset::where('user_id', $user->id)->findOrFail($id) : null;
        if ($errors = $this->errors($user, $settings, 'settings', $preset ? $this->pick($preset->settings ?? []) : [])) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($user, $name, $settings, $preset): MediaPreset {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $query = MediaPreset::where('user_id', $user->id);
            if ((clone $query)->where('name', $name)->when($preset, fn ($q) => $q->whereKeyNot($preset->id))->exists()) {
                throw ValidationException::withMessages(['name' => 'You already have a preset with this name.']);
            }
            if (! $preset && (clone $query)->count() >= self::MAX_PRESETS) {
                throw ValidationException::withMessages(['name' => 'You can keep up to 12 presets. Delete one to add another.']);
            }
            $preset ??= new MediaPreset(['user_id' => $user->id, 'position' => ((int) (clone $query)->max('position')) + 1]);
            $preset->fill(['name' => $name, 'settings' => $settings])->save();

            return $preset;
        });
    }

    public function duplicatePreset(User $user, int $id): MediaPreset
    {
        $source = MediaPreset::where('user_id', $user->id)->findOrFail($id);
        $names = MediaPreset::where('user_id', $user->id)->pluck('name')->all();
        $base = mb_substr($source->name, 0, 48);
        $name = $base.' (copy)';
        for ($number = 2; in_array($name, $names, true); $number++) {
            $name = $base.' (copy '.$number.')';
        }

        return $this->savePreset($user, $name, array_replace($this->baseline(), $this->pick($source->settings ?? [])));
    }

    public function deletePreset(User $user, int $id): void
    {
        MediaPreset::where('user_id', $user->id)->findOrFail($id)->delete();
    }

    /** @param  list<int>  $ids */
    public function orderPresets(User $user, array $ids): void
    {
        DB::transaction(function () use ($user, $ids): void {
            $owned = MediaPreset::where('user_id', $user->id)->lockForUpdate()->pluck('id')->all();
            sort($owned);
            $sorted = $ids;
            sort($sorted);
            if ($sorted !== $owned) {
                throw ValidationException::withMessages(['ids' => 'The preset list changed. Refresh and try again.']);
            }
            foreach ($ids as $position => $id) {
                MediaPreset::whereKey($id)->update(['position' => $position + 1]);
            }
        });
    }

    public function setDefaultPreset(User $user, ?int $id): void
    {
        if ($id !== null) {
            MediaPreset::where('user_id', $user->id)->findOrFail($id);
        }
        User::whereKey($user->id)->update(['default_preset_id' => $id]);
    }
}
