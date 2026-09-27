<?php

declare(strict_types=1);

return [
    'uri' => env('MONGO_URI', 'mongodb://toxiproxy:17017/?directConnection=true'),
    'database' => env('MONGO_DATABASE', 'commerce_read'),

    // The driver defaults are 10 s to connect, 30 s to find a server and 5 min for a reply.
    'options' => [
        'connectTimeoutMS' => (int) env('MONGO_CONNECT_TIMEOUT_MS', 2000),
        'serverSelectionTimeoutMS' => (int) env('MONGO_SERVER_SELECTION_TIMEOUT_MS', 2000),
        'socketTimeoutMS' => (int) env('MONGO_SOCKET_TIMEOUT_MS', 5000),
    ],
];
