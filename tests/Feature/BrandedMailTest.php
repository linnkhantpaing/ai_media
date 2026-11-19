<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\CustomerWelcome;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class BrandedMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_and_welcome_emails_use_the_branded_template_in_the_customer_language(): void
    {
        config(['app.url' => 'https://studio.example.test', 'mail.default' => 'array', 'mail.mailers.platform' => ['transport' => 'array']]);
        $customer = User::factory()->create(['is_admin' => false, 'name' => 'Soe', 'preferred_locale' => 'my']);

        Notification::sendNow($customer, new ResetPassword('reset-token'));
        Notification::sendNow($customer, new CustomerWelcome('https://studio.example.test/reset-password/welcome-token', 'https://studio.example.test/login', 'ZATLAN'));

        $sent = collect(Mail::mailer('array')->getSymfonyTransport()->messages())
            ->merge(Mail::mailer('platform')->getSymfonyTransport()->messages())
            ->map(fn ($message) => $message->getOriginalMessage());
        $this->assertCount(2, $sent);
        foreach ($sent as $email) {
            $this->assertStringContainsString('cid:', $email->getHtmlBody());
            $this->assertNotEmpty($email->getAttachments());
            $this->assertStringContainsString($customer->email, $email->getTextBody());
        }
        $reset = $sent->first(fn ($email) => str_contains($email->getHtmlBody(), 'reset-token'));
        $this->assertSame(trans('mail.reset.subject', ['app' => 'ZATLAN'], 'my'), $reset->getSubject());
        $this->assertStringContainsString('https://studio.example.test/reset-password/reset-token', $reset->getHtmlBody());
        $this->assertStringContainsString(e(trans('mail.reset.title', [], 'my')), $reset->getHtmlBody());
        $welcome = $sent->first(fn ($email) => str_contains($email->getHtmlBody(), 'welcome-token'));
        $this->assertStringContainsString(e(trans('mail.welcome.action', [], 'my')), $welcome->getHtmlBody());
    }
}
