<?php

declare(strict_types=1);

namespace App\Health;

use Illuminate\Database\DatabaseManager;

final readonly class DatabaseCheck implements HealthCheck
{
    public function __construct(private DatabaseManager $database) {}

    public function name(): string
    {
        return 'database';
    }

    public function check(): void
    {
        $this->database->connection()->select('select 1');
    }
}
