<?php

declare(strict_types=1);

namespace Tucano\FeatureFlags\Cache;

use Tucano\SharedKernel\Time\Clock;
use Tucano\SharedKernel\Time\SystemClock;

/**
 * Lives as long as the process: a whole Swoole worker or CLI consumer, but a
 * single request under PHP-FPM.
 */
final class InMemoryFlagCache implements FlagCache
{
    /** @var array<string, array{value: bool|int|float|string, expiresAt: int}> */
    private array $entries = [];

    public function __construct(private readonly Clock $clock = new SystemClock()) {}

    public function get(string $key): bool|int|float|string|null
    {
        $entry = $this->entries[$key] ?? null;
        if ($entry === null || $entry['expiresAt'] <= $this->now()) {
            return null;
        }

        return $entry['value'];
    }

    public function put(string $key, bool|int|float|string $value, int $ttlSeconds): void
    {
        $this->entries[$key] = ['value' => $value, 'expiresAt' => $this->now() + $ttlSeconds];
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }
}
