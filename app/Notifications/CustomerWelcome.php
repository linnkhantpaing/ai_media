<?php

namespace App\Notifications;

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
        $messages = [
            'en' => [
                'subject' => 'Your :app account is ready',
                'hello' => 'Hello :name',
                'created' => 'An administrator has created your :app account.',
                'email' => 'Login email: :email',
                'private' => 'Set your own password using the secure link below. Your password is never included in this email.',
                'action' => 'Set your password',
                'expiry' => 'This link expires in :minutes minutes and can be used once. If you request another link, use the latest email.',
                'login' => 'Sign in: :url',
                'contact' => 'If you did not expect this account, contact your administrator.',
            ],
            'my' => [
                'subject' => ':app အကောင့် ဖန်တီးပြီးပါပြီ',
                'hello' => 'မင်္ဂလာပါ :name',
                'created' => 'Admin က သင့်အတွက် :app အကောင့် ဖန်တီးပေးထားပါသည်။',
                'email' => 'ဝင်ရောက်ရန် အီးမေးလ်: :email',
                'private' => 'အောက်ပါ လုံခြုံသော link မှ သင်၏ စကားဝှက်ကို သတ်မှတ်ပါ။ ဤအီးမေးလ်တွင် စကားဝှက်အမှန်ကို မထည့်ပို့ပါ။',
                'action' => 'စကားဝှက် သတ်မှတ်ရန်',
                'expiry' => 'ဤ link သည် :minutes မိနစ်အတွင်း တစ်ကြိမ်သာ အသုံးပြုနိုင်ပါသည်။ Link ထပ်တောင်းထားပါက နောက်ဆုံးရရှိသော အီးမေးလ်ကို သုံးပါ။',
                'login' => 'ဝင်ရောက်ရန်: :url',
                'contact' => 'ဤအကောင့်ကို မမျှော်လင့်ထားပါက Admin ထံ ဆက်သွယ်ပါ။',
            ],
            'th' => [
                'subject' => 'บัญชี :app ของคุณพร้อมแล้ว',
                'hello' => 'สวัสดี :name',
                'created' => 'ผู้ดูแลระบบสร้างบัญชี :app ให้คุณแล้ว',
                'email' => 'อีเมลเข้าสู่ระบบ: :email',
                'private' => 'ตั้งรหัสผ่านของคุณผ่านลิงก์ที่ปลอดภัยด้านล่าง อีเมลนี้ไม่มีรหัสผ่านของคุณ',
                'action' => 'ตั้งรหัสผ่าน',
                'expiry' => 'ลิงก์นี้มีอายุ :minutes นาทีและใช้ได้ครั้งเดียว หากขอลิงก์ใหม่ให้ใช้อีเมลล่าสุด',
                'login' => 'เข้าสู่ระบบ: :url',
                'contact' => 'หากไม่ได้คาดว่าจะได้รับบัญชีนี้ โปรดติดต่อผู้ดูแลระบบ',
            ],
        ];
        $locale = $notifiable->preferred_locale ?? 'en';
        $text = $messages[$locale] ?? $messages['en'];
        $replace = [':app' => $this->platformName, ':name' => $notifiable->name, ':email' => $notifiable->email, ':minutes' => (string) $this->expiresMinutes, ':url' => $this->loginUrl];
        $line = fn (string $key): string => strtr($text[$key], $replace);

        return (new MailMessage)
            ->mailer('platform')
            ->subject($line('subject'))
            ->greeting($line('hello'))
            ->line($line('created'))
            ->line($line('email'))
            ->line($line('private'))
            ->action($line('action'), $this->resetUrl)
            ->line($line('expiry'))
            ->line($line('login'))
            ->line($line('contact'));
    }
}
