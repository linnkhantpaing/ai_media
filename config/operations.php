<?php

return [
    'heartbeat_interval' => 5,
    'worker_lock' => storage_path('framework/media-worker.lock'),
    'heartbeat_ttl' => 45,
    'poll_milliseconds' => 5000,
    'timezone' => 'Asia/Bangkok',
    'queues' => ['media-inspect', 'media'],
];
