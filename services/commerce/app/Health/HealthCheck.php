<?php

declare(strict_types=1);

namespace App\Health;

interface HealthCheck
{
    public const string TAG = 'health.checks';

    public function name(): string;

    /** Throws when the dependency is not usable. */
    public function check(): void;
}
