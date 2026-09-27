<?php

declare(strict_types=1);

namespace Tucano\FeatureFlags;

enum Environment: string
{
    case Local = 'local';
    case Staging = 'staging';
    case Production = 'production';

    /**
     * Anything unknown is treated as production: the most restrictive choice
     * is the safe default when the environment is misconfigured.
     */
    public static function fromName(?string $name): self
    {
        return self::tryFrom(strtolower(trim((string) $name))) ?? self::Production;
    }
}
