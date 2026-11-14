<?php

return [
    'portal_seed' => [
        'name' => env('ADMIN_PORTAL_SEED_NAME', 'Administrator'),
        'email' => env('ADMIN_PORTAL_SEED_EMAIL'),
        'password' => env('ADMIN_PORTAL_SEED_PASSWORD'),
    ],
    'seed' => [
        'enabled' => (bool) env('ADMIN_SEED_ENABLED', false),
        'name' => env('ADMIN_SEED_NAME', 'Admin'),
        'email' => env('ADMIN_SEED_EMAIL'),
        'password' => env('ADMIN_SEED_PASSWORD'),
    ],
];
