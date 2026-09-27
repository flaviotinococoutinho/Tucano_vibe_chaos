<?php

declare(strict_types=1);

namespace Tucano\FeatureFlags;

/** Flags held in memory: for tests of the services and for running without flagd. */
final class InMemoryFlags implements FeatureFlags
{
    /** @var array<string, int> */
    private array $reads = [];

    /** @param array<string, bool|int|float|string> $values */
    public function __construct(private array $values = []) {}

    public function set(string $flag, bool|int|float|string $value): void
    {
        $this->values[$flag] = $value;
    }

    public function enabled(string $flag, bool $fallback = false, ?FlagContext $context = null): bool
    {
        $value = $this->read($flag);

        return is_bool($value) ? $value : $fallback;
    }

    public function text(string $flag, string $fallback, ?FlagContext $context = null): string
    {
        $value = $this->read($flag);

        return is_string($value) ? $value : $fallback;
    }

    public function integer(string $flag, int $fallback, ?FlagContext $context = null): int
    {
        $value = $this->read($flag);

        return is_int($value) ? $value : $fallback;
    }

    public function decimal(string $flag, float $fallback, ?FlagContext $context = null): float
    {
        $value = $this->read($flag);

        return is_float($value) || is_int($value) ? (float) $value : $fallback;
    }

    public function readsOf(string $flag): int
    {
        return $this->reads[$flag] ?? 0;
    }

    private function read(string $flag): bool|int|float|string|null
    {
        $this->reads[$flag] = $this->readsOf($flag) + 1;

        return $this->values[$flag] ?? null;
    }
}
