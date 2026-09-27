<?php

declare(strict_types=1);

namespace Commerce\Payments\Domain;

/** The provider's token for a card. The card number itself never reaches Tucano. */
final readonly class CardToken
{
    private function __construct(public string $value) {}

    public static function of(string $value): self
    {
        if (preg_match('/^tok_[A-Za-z0-9_]{1,60}$/', $value) !== 1) {
            throw InvalidPayment::because(sprintf('"%s" is not a card token.', $value));
        }

        return new self($value);
    }
}
