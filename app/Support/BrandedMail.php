<?php

namespace App\Support;

use App\Services\GeneralSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Storage;

/**
 * Builds the premium ZATLAN account emails (password reset and admin-created welcome)
 * in the recipient's language, with the brand logo embedded inline (no public image URL needed).
 */
class BrandedMail
{
    public const LOCALES = ['en', 'my', 'th'];

    public static function reset(object $user, string $url, int $minutes): MailMessage
    {
        return self::build('reset', $user, $url, $minutes);
    }

    public static function welcome(object $user, string $url, string $loginUrl, int $minutes): MailMessage
    {
        return self::build('welcome', $user, $url, $minutes, $loginUrl);
    }

    private static function build(string $kind, object $user, string $url, int $minutes, ?string $loginUrl = null): MailMessage
    {
        $brand = self::brand();
        $locale = in_array($user->preferred_locale ?? null, self::LOCALES, true) ? $user->preferred_locale : 'en';
        $replace = ['app' => $brand['name'], 'name' => (string) ($user->name ?: $user->email), 'minutes' => $minutes, 'year' => now()->year];
        $text = fn (string $key, array $extra = []): string => (string) trans('mail.'.$key, [...$replace, ...$extra], $locale);
        $rows = [[$text('account'), (string) $user->email]];
        if ($loginUrl !== null) {
            $rows[] = [$text('welcome.sign_in'), preg_replace('#^https?://#', '', $loginUrl)];
        }
        $rows[] = [$text($kind.'.valid'), $text($kind.'.valid_value')];
        $contact = $brand['support_email'] ?: $brand['support_url'];

        $data = [
            'locale' => $locale,
            'brand' => $brand,
            'preheader' => $text($kind.'.preheader'),
            'eyebrow' => $text($kind.'.eyebrow'),
            'title' => $text($kind.'.title'),
            'hello' => $text($kind.'.hello'),
            'intro' => $text($kind.'.intro'),
            'features' => $kind === 'welcome' ? (array) trans('mail.welcome.features', [], $locale) : [],
            'private' => $kind === 'welcome' ? $text('welcome.private') : null,
            'rows' => $rows,
            'action' => $text($kind.'.action'),
            'url' => $url,
            'after' => $kind === 'welcome' ? $text('welcome.expired') : null,
            'noteTitle' => $text($kind === 'welcome' ? 'welcome.unexpected_title' : 'reset.ignore_title'),
            'note' => $text($kind === 'welcome' ? 'welcome.unexpected' : 'reset.ignore'),
            'fallback' => $text('fallback'),
            'support' => $contact ? $text('support', ['contact' => $contact]) : null,
            'supportHref' => $brand['support_email'] ? 'mailto:'.$brand['support_email'] : ($brand['support_url'] ?: null),
            'automated' => $text('automated'),
            'rights' => $text('rights'),
            ...self::logo($brand['logo_file']),
        ];

        $mail = (new MailMessage)
            ->subject($text($kind.'.subject'))
            ->greeting($data['hello'])
            ->line($data['intro']);
        foreach ($rows as [$label, $value]) {
            $mail->line($label.': '.$value);
        }

        return $mail->action($data['action'], $url)
            ->line($data['note'])
            ->view(['html' => 'mail.branded', 'text' => 'mail.branded-text'], $data);
    }

    private static function brand(): array
    {
        try {
            $settings = app(GeneralSettings::class)->current()['configuration'];
        } catch (\Throwable) {
            $settings = [];
        }

        return [
            'name' => (string) (($settings['name'] ?? '') ?: 'ZATLAN'),
            'tagline' => (string) ($settings['tagline'] ?? ''),
            'support_email' => (string) ($settings['support_email'] ?? ''),
            'support_url' => (string) ($settings['support_url'] ?? ''),
            'logo_file' => is_string($settings['main_logo']['file'] ?? null) ? $settings['main_logo']['file'] : null,
        ];
    }

    /**
     * The email header logo follows the main logo saved in Admin > General Settings. A large upload is
     * reduced once to a small PNG (cached by file name and change time) so the email stays light; a
     * missing or unreadable file falls back to the built-in ZATLAN logo.
     *
     * @return array{logoPath: string, logoWidth: int, logoHeight: int}
     */
    public static function logo(?string $file): array
    {
        $path = resource_path('brand/zatlan-logo.png');
        if ($file !== null && preg_match('/^[A-Za-z0-9._-]+$/D', $file)) {
            $path = self::mailCopy(Storage::disk('local')->path('branding/'.$file)) ?? $path;
        }
        [$width, $height] = @getimagesize($path) ?: [480, 160];
        // Shown at most 196px wide and 72px tall, keeping the logo's own shape.
        $shown = (int) max(1, min(196, round(72 * $width / max(1, $height))));

        return ['logoPath' => $path, 'logoWidth' => $shown, 'logoHeight' => (int) max(1, round($shown * $height / max(1, $width)))];
    }

    private static function mailCopy(string $source): ?string
    {
        $info = is_file($source) ? @getimagesize($source) : false;
        if (! $info || ! in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)) {
            return null;
        }
        if ($info[2] === IMAGETYPE_PNG && $info[0] <= 480 && filesize($source) <= 200 * 1024) {
            return $source;
        }
        $target = Storage::disk('local')->path('branding/mail/'.sha1($source.'|'.filemtime($source).'|'.filesize($source)).'.png');
        if (is_file($target)) {
            return $target;
        }
        if (! function_exists('imagecreatetruecolor')) {
            return null;
        }
        $image = match ($info[2]) {
            IMAGETYPE_PNG => @imagecreatefrompng($source),
            IMAGETYPE_JPEG => @imagecreatefromjpeg($source),
            default => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($source) : false,
        };
        if (! $image) {
            return null;
        }
        $scale = min(1, 480 / $info[0], 200 / $info[1]);
        $width = max(1, (int) round($info[0] * $scale));
        $height = max(1, (int) round($info[1] * $scale));
        $copy = imagecreatetruecolor($width, $height);
        imagealphablending($copy, false);
        imagesavealpha($copy, true);
        imagefill($copy, 0, 0, imagecolorallocatealpha($copy, 0, 0, 0, 127));
        imagecopyresampled($copy, $image, 0, 0, 0, 0, $width, $height, $info[0], $info[1]);
        Storage::disk('local')->makeDirectory('branding/mail');
        $saved = imagepng($copy, $target, 9);

        return $saved ? $target : null;
    }
}
