<?php

return [
    'automated' => 'This is an automated security message from :app. Please do not reply to this email.',
    'rights' => '© :year :app. All rights reserved.',
    'fallback' => 'Button not working? Copy and paste this link into your browser:',
    'account' => 'Account',
    'support' => 'Need help? Contact :contact',

    'reset' => [
        'subject' => 'Reset your :app password',
        'preheader' => 'Use this secure link to choose a new password. It expires in :minutes minutes.',
        'eyebrow' => 'Password reset',
        'title' => 'Reset your password',
        'hello' => 'Hi :name,',
        'intro' => 'We received a request to reset the password for your :app account. Choose a new password with the secure button below — it only takes a moment.',
        'action' => 'Reset Password',
        'valid' => 'Link valid for',
        'valid_value' => ':minutes minutes · single use',
        'ignore_title' => 'Didn’t request this?',
        'ignore' => 'You can safely ignore this email. Your current password stays the same, and no one can sign in without it.',
    ],

    'welcome' => [
        'subject' => 'Welcome to :app — your studio is ready',
        'preheader' => 'Your :app account is ready. Set your password to start creating.',
        'eyebrow' => 'Welcome to :app',
        'title' => 'Your studio is ready',
        'hello' => 'Hi :name,',
        'intro' => 'An administrator has created your :app account. Turn full-length movies into narrated recaps, dub existing videos and add subtitles — all from one private workspace.',
        'features' => ['Narrated movie recaps', 'Translation & dubbing', 'Subtitles in minutes'],
        'private' => 'To keep your account private, we never send passwords by email. Set your own password with the secure button below.',
        'action' => 'Set Your Password',
        'sign_in' => 'Sign in at',
        'valid' => 'Link valid for',
        'valid_value' => ':minutes minutes · single use',
        'expired' => 'If the link expires, use “Forgot password?” on the sign-in page to get a new one. Always use the most recent email.',
        'unexpected_title' => 'Not expecting this?',
        'unexpected' => 'If you did not expect a :app account, contact your administrator.',
    ],
];
