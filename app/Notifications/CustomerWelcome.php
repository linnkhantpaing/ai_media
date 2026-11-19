<?php

namespace App\Notifications;

use App\Support\BrandedMail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CustomerWelcome extends Notification
{
    public function __construct(
        public string $resetUrl,
        public string $loginUrl,
        public string $platformName,
        public int $expiresMinutes = 60,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return BrandedMail::welcome($notifiable, $this->resetUrl, $this->loginUrl, $this->expiresMinutes)->mailer('platform');
    }
}
