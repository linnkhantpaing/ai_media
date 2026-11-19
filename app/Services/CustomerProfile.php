<?php

namespace App\Services;

use App\Models\CustomerAccountEvent;
use App\Models\User;
use App\Services\Media\StorageSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * The signed-in customer's own profile, photo, password, date display and devices.
 * Package, status and allowances are never editable here.
 */
class CustomerProfile
{
    public const DATE_FORMATS = ['medium', 'dmy', 'mdy', 'iso'];

    /** Time zones customers can choose: Myanmar, Thailand, Japan and Korea. */
    public const TIMEZONES = ['Asia/Yangon', 'Asia/Bangkok', 'Asia/Tokyo', 'Asia/Seoul'];

    public const TIME_FORMATS = ['12', '24'];

    public function __construct(private StorageSettings $storage, private GeneralSettings $general) {}

    /** @return array<string, mixed> */
    public function present(User $user, string $currentSession): array
    {
        $assignment = $user->currentPackageAssignment;
        $platform = $this->general->publicData();

        return [
            'name' => $user->name, 'email' => $user->email, 'avatar_url' => $this->avatarUrl($user),
            'status' => $user->account_status, 'member_since' => $user->created_at?->toIso8601String(),
            'last_login_at' => $user->last_login_at?->toIso8601String(), 'password_changed_at' => $user->password_changed_at?->toIso8601String(),
            'plan' => $assignment ? ['name' => $assignment->effectiveSnapshot()['name'] ?? '', 'status' => $assignment->status(), 'ends_at' => $assignment->ends_at?->toIso8601String()] : null,
            'display' => $this->display($user),
            'platform_display' => ['timezone' => $platform['timezone'] ?? 'UTC', 'date_format' => $platform['date_format'] ?? 'medium', 'time_format' => $platform['time_format'] ?? '24'],
            'devices' => $this->devices($user, $currentSession),
            'photo_storage_ready' => $this->cloudReady(),
            'timezones' => self::TIMEZONES,
        ];
    }

    /**
     * Personal date/time display; null values follow the platform setting.
     *
     * @return array{timezone: ?string, date_format: ?string, time_format: ?string}
     */
    public function display(User $user): array
    {
        $saved = $user->display_preferences ?? [];

        $timezone = in_array($saved['timezone'] ?? null, self::TIMEZONES, true) ? $saved['timezone'] : null;

        return ['timezone' => $timezone, 'date_format' => $saved['date_format'] ?? null, 'time_format' => $saved['time_format'] ?? null];
    }

    /**
     * Platform display settings with the customer's personal overrides applied.
     *
     * @param  array<string, mixed>  $app
     * @return array<string, mixed>
     */
    public function applyDisplay(array $app, User $user): array
    {
        return array_replace($app, array_filter($this->display($user), fn (?string $value): bool => $value !== null));
    }

    /** @return array<string, list<mixed>> */
    public static function rules(): array
    {
        return [
            'profile' => ['required', 'array'],
            'profile.name' => ['required', 'string', 'max:100'],
            'profile.display' => ['required', 'array'],
            'profile.display.timezone' => ['present', 'nullable', 'string', 'in:'.implode(',', self::TIMEZONES)],
            'profile.display.date_format' => ['present', 'nullable', 'in:'.implode(',', self::DATE_FORMATS)],
            'profile.display.time_format' => ['present', 'nullable', 'in:'.implode(',', self::TIME_FORMATS)],
        ];
    }

    /**
     * Saves the display name and date/time display (part of the Preferences draft).
     *
     * @param  array{name: string, display: array<string, ?string>}  $profile
     */
    public function saveProfile(User $user, array $profile): void
    {
        $name = trim($profile['name']);
        if ($name === '') {
            throw ValidationException::withMessages(['profile.name' => 'Enter your name.']);
        }
        $display = array_filter(array_intersect_key($profile['display'], array_flip(['timezone', 'date_format', 'time_format'])), fn (?string $value): bool => $value !== null && $value !== '');
        $before = $user->name;
        $user->forceFill(['name' => $name, 'display_preferences' => $display === [] ? null : $display])->save();
        if ($before !== $name) {
            $this->event($user, 'self_profile_updated', ['fields' => ['name']]);
        }
    }

    public function changeEmail(User $user, string $email, string $password): void
    {
        $this->confirmPassword($user, $password);
        $email = Str::lower(trim($email));
        if ($email === Str::lower($user->email)) {
            throw ValidationException::withMessages(['email' => 'This is already your email address.']);
        }
        if (User::where('email', $email)->whereKeyNot($user->id)->exists()) {
            throw ValidationException::withMessages(['email' => 'This email address is already used by another account.']);
        }
        DB::transaction(function () use ($user, $email): void {
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            $user->forceFill(['email' => $email, 'email_verified_at' => null])->save();
            $this->event($user, 'self_email_changed', ['fields' => ['email']]);
        });
    }

    public function changePassword(User $user, string $current, string $password): void
    {
        $this->confirmPassword($user, $current, 'current_password');
        if (Hash::check($password, $user->password)) {
            throw ValidationException::withMessages(['password' => 'Choose a password you have not used for this account.']);
        }
        DB::transaction(function () use ($user, $password): void {
            $user->forceFill(['password' => Hash::make($password), 'password_changed_at' => now()])->save();
            $this->event($user, 'self_password_changed', []);
        });
    }

    /**
     * Ends every other session of this customer, including "remember me" logins.
     */
    public function signOutOtherDevices(User $user, string $currentSession): int
    {
        $removed = DB::table('sessions')->where('user_id', $user->id)->where('id', '!=', $currentSession)->delete();
        $user->forceFill(['remember_token' => Str::random(60), 'auth_version' => (int) $user->auth_version + 1])->save();
        $this->event($user, 'self_sessions_revoked', ['sessions' => $removed]);

        return $removed;
    }

    public function signOutDevice(User $user, string $key, string $currentSession): void
    {
        $session = collect(DB::table('sessions')->where('user_id', $user->id)->pluck('id'))
            ->first(fn (string $id): bool => hash_equals($this->deviceKey($id), $key));
        abort_unless($session, 404);
        abort_if($session === $currentSession, 422, 'Use Sign out to end this session.');
        DB::table('sessions')->where('id', $session)->delete();
        $this->event($user, 'self_session_revoked', []);
    }

    /** @return list<array<string, mixed>> */
    public function devices(User $user, string $currentSession): array
    {
        if (config('session.driver') !== 'database') {
            return [];
        }

        return DB::table('sessions')->where('user_id', $user->id)->orderByDesc('last_activity')->limit(20)->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(fn (object $session): array => [
                'key' => $this->deviceKey($session->id), 'current' => $session->id === $currentSession,
                ...$this->agent((string) $session->user_agent),
                'ip' => $this->maskIp((string) $session->ip_address),
                'last_active' => now()->setTimestamp((int) $session->last_activity)->toIso8601String(),
            ])->sortByDesc('current')->values()->all();
    }

    /**
     * Stores a square profile photo in the configured cloud storage (never on the web server).
     */
    public function saveAvatar(User $user, UploadedFile $file): void
    {
        if (! $this->cloudReady()) {
            throw ValidationException::withMessages(['photo' => 'Profile photos need cloud storage. Contact Support.']);
        }
        $info = @getimagesize($file->getRealPath());
        if (! $info || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true) || $info[0] < 64 || $info[1] < 64 || $info[0] > 4096 || $info[1] > 4096) {
            throw ValidationException::withMessages(['photo' => 'Choose a JPG, PNG or WEBP image between 64 and 4096 pixels.']);
        }
        $contents = $this->squareWebp($file->getRealPath(), $info[2]);
        $profile = $this->storage->capture();
        $path = 'avatars/'.$user->id.'/'.Str::uuid().'.webp';
        $disk = $this->storage->filesystem($profile);
        $disk->put($path, $contents, ['visibility' => 'private', 'ContentType' => 'image/webp']);
        $old = [$user->avatar_path, $user->avatar_profile_id];
        $user->forceFill(['avatar_path' => $path, 'avatar_profile_id' => $profile])->save();
        $this->deleteObject($old[0], $old[1]);
        $this->event($user, 'self_photo_updated', []);
    }

    public function removeAvatar(User $user): void
    {
        $old = [$user->avatar_path, $user->avatar_profile_id];
        $user->forceFill(['avatar_path' => null, 'avatar_profile_id' => null])->save();
        $this->deleteObject($old[0], $old[1]);
    }

    public function avatarResponse(User $user): StreamedResponse
    {
        abort_unless($user->avatar_path && $user->avatar_profile_id, 404);
        $disk = $this->storage->filesystem($user->avatar_profile_id);
        abort_unless($disk->exists($user->avatar_path), 404);

        return $disk->response($user->avatar_path, 'avatar.webp', ['Content-Type' => 'image/webp', 'Cache-Control' => 'private, max-age=604800, immutable', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function avatarUrl(User $user): ?string
    {
        return $user->avatar_path ? route('settings.preferences.avatar', ['v' => substr(md5($user->avatar_path), 0, 10)], absolute: false) : null;
    }

    private function cloudReady(): bool
    {
        try {
            return $this->storage->connection()['disk'] !== 'local';
        } catch (Throwable) {
            return false;
        }
    }

    /** Re-encodes the image as a 512px square WEBP, removing camera metadata. */
    private function squareWebp(string $path, int $type): string
    {
        $source = match ($type) {
            IMAGETYPE_JPEG => imagecreatefromjpeg($path), IMAGETYPE_PNG => imagecreatefrompng($path), default => imagecreatefromwebp($path),
        };
        if (! $source) {
            throw ValidationException::withMessages(['photo' => 'This image could not be read.']);
        }
        $width = imagesx($source);
        $height = imagesy($source);
        $side = min($width, $height);
        $size = min(512, $side);
        $canvas = imagecreatetruecolor($size, $size);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagecopyresampled($canvas, $source, 0, 0, intdiv($width - $side, 2), intdiv($height - $side, 2), $size, $size, $side, $side);
        ob_start();
        imagewebp($canvas, null, 86);
        $contents = (string) ob_get_clean();
        imagedestroy($canvas);
        imagedestroy($source);

        return $contents;
    }

    private function deleteObject(?string $path, ?int $profile): void
    {
        if (! $path || ! $profile) {
            return;
        }
        try {
            $this->storage->filesystem($profile)->delete($path);
        } catch (Throwable $error) {
            report($error);
        }
    }

    private function confirmPassword(User $user, string $password, string $field = 'password'): void
    {
        if (! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([$field => 'Your current password is incorrect.']);
        }
    }

    private function deviceKey(string $session): string
    {
        return substr(hash_hmac('sha256', $session, (string) config('app.key')), 0, 32);
    }

    private function maskIp(string $ip): string
    {
        if (str_contains($ip, ':')) {
            return implode(':', array_slice(explode(':', $ip), 0, 3)).':…';
        }
        $parts = explode('.', $ip);

        return count($parts) === 4 ? $parts[0].'.'.$parts[1].'.xx.xx' : '—';
    }

    /** @return array{browser: string, os: string, kind: string} */
    private function agent(string $agent): array
    {
        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge', str_contains($agent, 'OPR/') => 'Opera', str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Chrome/') => 'Chrome', str_contains($agent, 'Safari/') => 'Safari', default => 'Browser',
        };
        $os = match (true) {
            str_contains($agent, 'Windows') => 'Windows', str_contains($agent, 'Android') => 'Android', (bool) preg_match('/iPhone|iPad/', $agent) => 'iOS',
            str_contains($agent, 'Mac OS X') => 'macOS', str_contains($agent, 'Linux') => 'Linux', default => '',
        };

        return ['browser' => $browser, 'os' => $os, 'kind' => in_array($os, ['Android', 'iOS'], true) ? 'mobile' : 'desktop'];
    }

    /** @param  array<string, mixed>  $metadata */
    private function event(User $user, string $type, array $metadata): void
    {
        CustomerAccountEvent::create(['user_id' => $user->id, 'actor_id' => $user->id, 'type' => $type, 'metadata' => $metadata]);
    }
}
