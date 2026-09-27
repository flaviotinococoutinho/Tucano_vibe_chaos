<?php

declare(strict_types=1);

namespace Tucano\FeatureFlags;

/**
 * Decorator that keeps chaos and lab flags off in production, even if someone
 * adds them to the production flag file by mistake.
 */
final readonly class ProductionGuard implements FeatureFlags
{
    private const array RESTRICTED_PREFIXES = ['chaos.', 'labs.'];

    public function __construct(
        private FeatureFlags $inner,
        private Environment $environment,
    ) {}

    public function enabled(string $flag, bool $fallback = false, ?FlagContext $context = null): bool
    {
        return $this->isRestricted($flag) ? $fallback : $this->inner->enabled($flag, $fallback, $context);
    }

    public function text(string $flag, string $fallback, ?FlagContext $context = null): string
    {
        return $this->isRestricted($flag) ? $fallback : $this->inner->text($flag, $fallback, $context);
    }

    public function integer(string $flag, int $fallback, ?FlagContext $context = null): int
    {
        return $this->isRestricted($flag) ? $fallback : $this->inner->integer($flag, $fallback, $context);
    }

    public function decimal(string $flag, float $fallback, ?FlagContext $context = null): float
    {
        return $this->isRestricted($flag) ? $fallback : $this->inner->decimal($flag, $fallback, $context);
    }

    private function isRestricted(string $flag): bool
    {
        if ($this->environment !== Environment::Production) {
            return false;
        }

        // array_any() would read better, but it only exists from PHP 8.4 and the catalog runs 8.3.
        $matches = array_filter(self::RESTRICTED_PREFIXES, static fn(string $prefix): bool => str_starts_with($flag, $prefix));

        return $matches !== [];
    }
}
