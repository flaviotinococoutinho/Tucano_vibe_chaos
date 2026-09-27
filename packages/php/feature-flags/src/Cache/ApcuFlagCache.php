<?php

declare(strict_types=1);

namespace Tucano\FeatureFlags\Cache;

/** Shared by every PHP-FPM child of the pool, so one flag lookup serves many requests. */
final readonly class ApcuFlagCache implements FlagCache
{
    public function __construct(private string $prefix = 'flags') {}

    public function get(string $key): bool|int|float|string|null
    {
        $value = apcu_fetch($this->prefix . ':' . $key, $found);
        if (!$found || !(is_bool($value) || is_int($value) || is_float($value) || is_string($value))) {
            return null;
        }

        return $value;
    }

    public function put(string $key, bool|int|float|string $value, int $ttlSeconds): void
    {
        apcu_store($this->prefix . ':' . $key, $value, $ttlSeconds);
    }
}
