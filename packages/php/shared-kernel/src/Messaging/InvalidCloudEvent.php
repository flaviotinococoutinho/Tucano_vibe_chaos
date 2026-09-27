<?php

declare(strict_types=1);

namespace Tucano\SharedKernel\Messaging;

use InvalidArgumentException;

final class InvalidCloudEvent extends InvalidArgumentException
{
    public static function missing(string $attribute): self
    {
        return new self(sprintf('CloudEvent attribute "%s" is missing or empty.', $attribute));
    }

    public static function unsupportedVersion(string $version): self
    {
        return new self(sprintf('CloudEvents spec version "%s" is not supported.', $version));
    }
}
