<?php

declare(strict_types=1);

// The labels go to S3 through their own client (config/labels.php); the framework only needs a local disk.
return [
    'default' => 'local',
    'disks' => [
        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'throw' => false,
            'report' => false,
        ],
    ],
];
