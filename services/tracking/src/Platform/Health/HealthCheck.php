<?php

declare(strict_types=1);

namespace Tracking\Platform\Health;

interface HealthCheck
{
    public function name(): string;

    /** Throws when the dependency is not usable. */
    public function check(): void;
}
