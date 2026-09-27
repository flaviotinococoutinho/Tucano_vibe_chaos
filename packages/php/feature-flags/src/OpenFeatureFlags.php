<?php

declare(strict_types=1);

namespace Tucano\FeatureFlags;

use OpenFeature\implementation\flags\Attributes;
use OpenFeature\implementation\flags\EvaluationContext;
use OpenFeature\interfaces\flags\Client;

/** Adapter from the FeatureFlags port to an OpenFeature client. */
final readonly class OpenFeatureFlags implements FeatureFlags
{
    public function __construct(private Client $client) {}

    public function enabled(string $flag, bool $fallback = false, ?FlagContext $context = null): bool
    {
        return $this->client->getBooleanValue($flag, $fallback, self::toOpenFeature($context)) ?? $fallback;
    }

    public function text(string $flag, string $fallback, ?FlagContext $context = null): string
    {
        return $this->client->getStringValue($flag, $fallback, self::toOpenFeature($context)) ?? $fallback;
    }

    public function integer(string $flag, int $fallback, ?FlagContext $context = null): int
    {
        return $this->client->getIntegerValue($flag, $fallback, self::toOpenFeature($context));
    }

    public function decimal(string $flag, float $fallback, ?FlagContext $context = null): float
    {
        return $this->client->getFloatValue($flag, $fallback, self::toOpenFeature($context));
    }

    private static function toOpenFeature(?FlagContext $context): ?EvaluationContext
    {
        if ($context === null) {
            return null;
        }

        return new EvaluationContext($context->targetingKey, new Attributes($context->attributes));
    }
}
