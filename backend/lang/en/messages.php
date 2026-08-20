<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Messages
    |--------------------------------------------------------------------------
    |
    | Messages raised by the application itself (abort(), exceptions, API
    | responses). Keep every key mirrored in lang/zh-TW/messages.php.
    |
    */

    'errors' => [
        'unauthenticated' => 'Unauthenticated.',
        'forbidden' => 'You are not allowed to perform this action.',
        'not_found' => 'The requested resource was not found.',
        'server_error' => 'Something went wrong. Please try again later.',
    ],

    'auth' => [
        'logged_out' => 'You have been signed out.',
    ],

    'sports' => [
        'not_tagged' => 'This sport is not in your list.',
    ],

    'activities' => [
        'full' => 'This activity is already full.',
        'closed' => 'This activity is no longer taking registrations.',
    ],

    'idempotency' => [
        'in_progress' => 'An identical request is still being processed. Please retry in a moment.',
        'reused' => 'This idempotency key was already used for a different request.',
        'invalid' => 'The idempotency key is not a valid length.',
    ],

    'throttle' => [
        'too_many' => 'Too many registration attempts. Please try again shortly.',
    ],

];
