<?php

declare(strict_types=1);

namespace Tucano\FeatureFlags;

use Tucano\FeatureFlags\Cache\FlagCache;

/**
 * Decorator that keeps evaluations for a couple of seconds. The PHP flagd
 * provider makes one HTTP call per evaluation; the cache turns a hot flag
 * into one call every few seconds, at the cost of changes taking that long
 * to show up.
 */
final readonly class CachedFlags implements FeatureFlags
{
    public function __construct(
        private FeatureFlags $inner,
        private FlagCache $cache,
        private int $ttlSeconds = 2,
    ) {}

    public function enabled(string $flag, bool $fallback = false, ?FlagContext $context = null): bool
    {
        $cached = $this->cache->get($this->key('bool', $flag, $context));
        if (is_bool($cached)) {
            return $cached;
        }

        return $this->remember('bool', $flag, $context, $this->inner->enabled($flag, $fallback, $context));
    }

    public function text(string $flag, string $fallback, ?FlagContext $context = null): string
    {
        $cached = $this->cache->get($this->key('text', $flag, $context));
        if (is_string($cached)) {
            return $cached;
        }

        return $this->remember('text', $flag, $context, $this->inner->text($flag, $fallback, $context));
    }

    public function integer(string $flag, int $fallback, ?FlagContext $context = null): int
    {
        $cached = $this->cache->get($this->key('int', $flag, $context));
        if (is_int($cached)) {
            return $cached;
        }

        return $this->remember('int', $flag, $context, $this->inner->integer($flag, $fallback, $context));
    }

    public function decimal(string $flag, float $fallback, ?FlagContext $context = null): float
    {
        $cached = $this->cache->get($this->key('float', $flag, $context));
        if (is_float($cached)) {
            return $cached;
        }

        return $this->remember('float', $flag, $context, $this->inner->decimal($flag, $fallback, $context));
    }

    /**
     * @template T of bool|int|float|string
     *
     * @param T $value
     *
     * @return T
     */
    private function remember(string $type, string $flag, ?FlagContext $context, bool|int|float|string $value): bool|int|float|string
    {
        $this->cache->put($this->key($type, $flag, $context), $value, $this->ttlSeconds);

        return $value;
    }

    private function key(string $type, string $flag, ?FlagContext $context): string
    {
        return sprintf('%s:%s:%s', $type, $flag, $context?->fingerprint() ?? 'anonymous');
    }
}
