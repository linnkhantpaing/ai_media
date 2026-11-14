<?php

namespace App\Services;

use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class GeneralSettings
{
    public function defaults(): array
    {
        return ['name' => 'ZATLAN', 'tagline' => 'AI Video Recap Studio', 'language' => 'my', 'timezone' => 'Asia/Bangkok',
            'date_format' => 'medium', 'time_format' => '24', 'support_email' => '', 'support_phone' => '', 'support_url' => '',
            'main_logo' => null, 'light_logo' => null, 'favicon' => null,
            'smtp' => ['enabled' => false, 'host' => '', 'port' => 587, 'encryption' => 'tls', 'username' => '', 'password' => '', 'from_name' => 'ZATLAN', 'from_email' => '', 'reply_to' => ''],
            'maintenance' => ['enabled' => false, 'message' => "ZATLAN is currently under maintenance. We'll be back shortly. Thank you for your patience."]];
    }

    public function current(): array
    {
        $row = PlatformSetting::find(1);

        return ['revision' => $row?->revision ?? 0, 'saved_at' => $row?->updated_at?->toIso8601String(), 'configuration' => array_replace_recursive($this->defaults(), $row?->configuration ?? []), 'mail_report' => $row?->mail_report];
    }

    public function publicData(?array $state = null): array
    {
        $c = ($state ?? $this->current())['configuration'];

        return array_intersect_key($c, array_flip(['name', 'tagline', 'language', 'timezone', 'date_format', 'time_format', 'support_email', 'support_phone', 'support_url', 'maintenance'])) +
         ['logo' => $this->assetUrl($c['main_logo']) ?? '/images/zatlan-3d-film-logo.png', 'lightLogo' => $this->assetUrl($c['light_logo']), 'favicon' => $this->assetUrl($c['favicon']) ?? '/favicon.svg'];
    }

    public function adminData(): array
    {
        $state = $this->current();
        $c = $state['configuration'];
        $has = (bool) $c['smtp']['password'];
        $c['smtp']['password'] = '';
        foreach (['main_logo', 'light_logo', 'favicon'] as $field) {
            $c[$field] = $c[$field] ? [...$c[$field], 'url' => $this->assetUrl($c[$field])] : null;
        }

        return [...$state, 'configuration' => $c, 'has_password' => $has];
    }

    private function assetUrl(?array $asset): ?string
    {
        return $asset ? '/branding/'.$asset['file'] : null;
    }

    public function save(array $data, array $uploads, User $actor): array
    {
        $newFiles = [];
        try {
            DB::transaction(function () use ($data, $uploads, $actor, &$newFiles): void {
                DB::table('platform_settings')->insertOrIgnore(['id' => 1, 'revision' => 0, 'configuration' => Crypt::encryptString(json_encode($this->defaults())), 'created_at' => now(), 'updated_at' => now()]);
                $row = PlatformSetting::lockForUpdate()->findOrFail(1);
                if ($row->revision !== (int) $data['revision']) {
                    throw new ConflictHttpException('settings_conflict');
                }
                $old = array_replace_recursive($this->defaults(), $row->configuration);
                $c = $data;
                unset($c['revision'],$c['confirm_maintenance']);
                foreach (['main_logo', 'light_logo', 'favicon'] as $field) {
                    $c[$field] = $old[$field];
                    if (isset($uploads[$field])) {
                        $file = $uploads[$field];
                        $name = (string) Str::uuid().'.'.($file->guessExtension() === 'ico' ? 'ico' : $file->extension());
                        if (! $file->storeAs('branding', $name, 'local')) {
                            throw new \RuntimeException('branding_upload_failed');
                        }
                        $newFiles[] = 'branding/'.$name;
                        $c[$field] = ['file' => $name, 'name' => mb_substr(basename($file->getClientOriginalName()), 0, 120), 'bytes' => $file->getSize()];
                    }
                }
                if ($c['smtp']['password'] === '') {
                    $c['smtp']['password'] = $old['smtp']['password'];
                }
                $changed = array_values(array_filter(array_keys($c), fn ($key) => $c[$key] !== $old[$key]));
                $report = $c['smtp'] === $old['smtp'] ? $row->mail_report : null;
                $row->forceFill(['revision' => $row->revision + 1, 'configuration' => $c, 'updated_by' => $actor->id, 'mail_report' => $report])->save();
                $auditId = DB::table('platform_setting_events')->insertGetId(['revision' => $row->revision, 'actor_id' => $actor->id, 'action' => 'configuration_saved', 'fields' => json_encode($changed), 'created_at' => now()]);
                app(AuditTrail::class)->capture('general', $auditId);
            });
        } catch (\Throwable $e) {
            foreach ($newFiles as $file) {
                Storage::disk('local')->delete($file);
            } throw $e;
        }

        return $this->adminData();
    }

    public function mailConfig(array $smtp): array
    {
        return ['transport' => 'smtp', 'scheme' => $smtp['encryption'] === 'ssl' ? 'smtps' : 'smtp', 'host' => $smtp['host'], 'port' => $smtp['port'], 'username' => $smtp['username'] ?: null, 'password' => $smtp['password'] ?: null, 'timeout' => 10, 'require_tls' => $smtp['encryption'] === 'tls', 'auto_tls' => $smtp['encryption'] !== 'none'];
    }

    public function recordMailReport(int $revision, array $report, User $actor): void
    {
        DB::transaction(function () use ($revision, $report, $actor): void {
            $row = PlatformSetting::lockForUpdate()->findOrFail(1);
            if ($row->revision !== $revision) {
                throw new ConflictHttpException('settings_conflict');
            }
            $reports = $row->mail_report ?? [];
            $reports[$report['kind']] = $report;
            DB::table('platform_settings')->where('id', 1)->update(['mail_report' => json_encode($reports)]);
            $auditId = DB::table('platform_setting_events')->insertGetId(['revision' => $revision, 'actor_id' => $actor->id, 'action' => 'smtp_'.$report['kind'].'_'.$report['status'], 'fields' => '[]', 'created_at' => now()]);
            app(AuditTrail::class)->capture('general', $auditId);
        });
    }
}
