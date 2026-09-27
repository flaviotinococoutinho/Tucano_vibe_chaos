<?php

declare(strict_types=1);

namespace Commerce\Shared\Application\Idempotency;

/** The value of the Idempotency-Key header: 1 to 64 printable ASCII characters. */
final readonly class IdempotencyKey
{
    private function __construct(public string $value) {}

    public static function of(string $value): self
    {
        if (preg_match('/^[\x21-\x7E]{1,64}$/', $value) !== 1) {
            throw new InvalidIdempotencyKey(sprintf('An idempotency key has 1 to 64 printable ASCII characters, got "%s".', $value));
        }

        return new self($value);
    }
}
