<?php

namespace App\Services\Media;

use App\Services\AuditTrail;
use App\Support\MediaSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ProjectDefaults
{
    public function __construct(private ProviderSettings $providers) {}

    public function recommended(): array
    {
        return MediaSettings::normalize(array_replace(MediaSettings::DEFAULTS, $this->voiceDefaults()));
    }

    public function voiceDefaults(): array
    {
        return array_intersect_key($this->providers->current(), array_flip(['voice', 'speech_rate', 'voice_volume']));
    }

    public function resolved(): array
    {
        return $this->present()['settings'];
    }

    public function present(): array
    {
        $row = DB::table('media_project_defaults')->where('id', 1)->first();
        $settings = $row?->settings ? json_decode($row->settings, true, 512, JSON_THROW_ON_ERROR) : $this->recommended();
        $inherit = $row === null || (bool) $row->inherit_voice;
        if ($inherit) {
            $settings = array_replace($settings, $this->voiceDefaults());
        }

        return [
            'revision' => (int) ($row?->revision ?? 0),
            'settings' => MediaSettings::normalize($settings),
            'inherit_voice' => $inherit,
            'savedAt' => $row?->updated_at ? Carbon::parse($row->updated_at)->toIso8601String() : null,
        ];
    }

    public function save(array $settings, bool $inherit, int $expected, int $userId): void
    {
        DB::transaction(function () use ($settings, $inherit, $expected, $userId): void {
            $row = DB::table('media_project_defaults')->where('id', 1)->lockForUpdate()->first();
            abort_if(! $row || (int) $row->revision !== $expected, 409, 'settings_conflict');
            DB::table('media_project_defaults')->where('id', 1)->update([
                'revision' => $expected + 1,
                'settings' => json_encode(MediaSettings::normalize($settings), JSON_THROW_ON_ERROR),
                'inherit_voice' => $inherit,
                'updated_by' => $userId,
                'created_at' => $row->created_at ?? now(),
                'updated_at' => now(),
            ]);
            app(AuditTrail::class)->settings('defaults', $userId);
        });
    }
}
