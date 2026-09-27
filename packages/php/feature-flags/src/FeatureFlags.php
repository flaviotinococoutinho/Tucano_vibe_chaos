<?php

declare(strict_types=1);

namespace Tucano\FeatureFlags;

/**
 * Port used by the services to read feature flags. Every call carries a
 * fallback: when the flag is missing or the flag server is down, the caller
 * gets the conservative behavior instead of an error.
 */
interface FeatureFlags
{
    public function enabled(string $flag, bool $fallback = false, ?FlagContext $context = null): bool;

    public function text(string $flag, string $fallback, ?FlagContext $context = null): string;

    public function integer(string $flag, int $fallback, ?FlagContext $context = null): int;

    public function decimal(string $flag, float $fallback, ?FlagContext $context = null): float;
}
