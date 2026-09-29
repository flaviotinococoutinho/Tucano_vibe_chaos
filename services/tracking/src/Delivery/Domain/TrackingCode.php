<?php

declare(strict_types=1);

namespace Tracking\Delivery\Domain;

use InvalidArgumentException;

/** TX and 13 characters of Crockford's Base32, the code a customer follows a parcel by. */
final readonly class TrackingCode
{
    private const string PATTERN = '/^TX[0-9A-HJKMNP-TV-Z]{13}$/';

    private function __construct(public string $value) {}

    public static function of(string $value): self
    {
        return preg_match(self::PATTERN, $value) === 1
            ? new self($value)
            : throw new InvalidArgumentException("A tracking code is TX and 13 characters of Crockford's Base32.");
    }
}
