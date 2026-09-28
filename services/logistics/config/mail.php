<?php

declare(strict_types=1);

return [
    // smtp in the stack (Mailpit, through Toxiproxy), array in the tests.
    'default' => env('MAIL_MAILER', 'smtp'),
    'mailers' => [
        'smtp' => [
            'transport' => 'smtp',
            'host' => env('MAIL_HOST', 'toxiproxy'),
            'port' => (int) env('MAIL_PORT', 11025),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            // A mail server that stops answering would hold the round of the watch that sends the alert.
            'timeout' => (int) env('MAIL_TIMEOUT_SECONDS', 5),
            'local_domain' => 'logistics.tucano.local',
        ],
        'array' => [
            'transport' => 'array',
        ],
    ],
    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'logistics@tucano.local'),
        'name' => env('MAIL_FROM_NAME', 'Tucano logistics'),
    ],
];
