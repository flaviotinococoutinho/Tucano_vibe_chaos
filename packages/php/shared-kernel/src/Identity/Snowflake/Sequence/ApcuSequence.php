<?php

declare(strict_types=1);

namespace Tucano\SharedKernel\Identity\Snowflake\Sequence;

use RuntimeException;

/**
 * For PHP-FPM. Every request runs in an isolated child process, so an
 * in-memory counter would repeat across children in the same millisecond.
 * APCu memory is shared by all children of the pool and apcu_inc is atomic.
 */
final readonly class ApcuSequence implements SequenceProvider
{
    private const int TTL_SECONDS = 2;

    public function __construct(private string $namespace) {}

    public function next(int $millis): int
    {
        $key = sprintf('%s:%d', $this->namespace, $millis);
        apcu_add($key, -1, self::TTL_SECONDS);
        $sequence = apcu_inc($key, 1, $success, self::TTL_SECONDS);

        if (!$success || !is_int($sequence)) {
            throw new RuntimeException('APCu is not available; enable apc.enabled (and apc.enable_cli for the CLI).');
        }

        return $sequence;
    }
}
