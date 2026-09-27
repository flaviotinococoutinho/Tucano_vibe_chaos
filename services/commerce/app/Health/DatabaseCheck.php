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

    /**
     * Runs the query on PDO itself. Through the connection, a lost connection would be
     * reconnected and the query run again, hiding the failure this check exists to report
     * and doubling the time it takes. Opening the connection still gets one retry.
     */
    public function check(): void
    {
        $this->database->connection()->getPdo()->query('select 1');
    }
}
