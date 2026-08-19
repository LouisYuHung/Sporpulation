<?php

return [
    'registration' => [
        'limit' => env('THROTTLE_REGISTRATION_LIMIT', 5),
        'window' => env('THROTTLE_REGISTRATION_WINDOW', 60),  // 秒
        'connection' => 'idempotency',
    ],
];
