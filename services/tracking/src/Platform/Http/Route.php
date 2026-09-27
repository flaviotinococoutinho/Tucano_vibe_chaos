<?php

declare(strict_types=1);

namespace Tracking\Platform\Http;

use Closure;

final readonly class Route
{
    /** @param Closure(Request): Response $handler */
    public function __construct(
        public string $method,
        public string $path,
        public Closure $handler,
    ) {}
}
