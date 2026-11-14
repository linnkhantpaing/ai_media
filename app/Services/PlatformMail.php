<?php

namespace App\Services;

use Illuminate\Mail\MailManager;

class PlatformMail
{
    public function test(array $configuration, ?string $recipient): void
    {
        $smtp = $configuration['smtp'];
        $mailer = app(MailManager::class)->build(app(GeneralSettings::class)->mailConfig($smtp));
        $transport = $mailer->getSymfonyTransport();
        try {
            $transport->start();
            if ($recipient !== null) {
                $mailer->raw('This is a manually requested SMTP test from '.$configuration['name'].'. No customer account email has been sent.', function ($message) use ($smtp, $recipient, $configuration): void {
                    $message->to($recipient)->from($smtp['from_email'], $smtp['from_name'])->subject($configuration['name'].' SMTP test');
                    if ($smtp['reply_to']) {
                        $message->replyTo($smtp['reply_to']);
                    }
                });
            }
        } finally {
            $transport->stop();
        }
    }
}
