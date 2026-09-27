<?php

declare(strict_types=1);

namespace Tucano\Messaging\Kafka;

use RuntimeException;

final class DeliveryFailed extends RuntimeException
{
    /** @param list<string> $reasons */
    public static function because(array $reasons): self
    {
        return new self('Kafka did not acknowledge every message: ' . implode('; ', $reasons));
    }
}
