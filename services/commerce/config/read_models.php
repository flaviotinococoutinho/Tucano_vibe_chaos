<?php

declare(strict_types=1);

return [
    'uri' => env('MONGO_URI', 'mongodb://toxiproxy:17017/?directConnection=true'),
    'database' => env('MONGO_DATABASE', 'commerce_read'),
];
