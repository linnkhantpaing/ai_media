<?php

namespace App\Support;

use App\Services\GeneralSettings;
use Illuminate\Notifications\Messages\MailMessage;

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
            'logoPath' => resource_path('mail/zatlan-logo.png'),
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
        ];
    }
}
