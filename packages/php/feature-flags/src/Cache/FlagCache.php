<?php

declare(strict_types=1);

namespace Tucano\FeatureFlags\Cache;

interface FlagCache
{
    public function get(string $key): bool|int|float|string|null;

    public function put(string $key, bool|int|float|string $value, int $ttlSeconds): void;
}
