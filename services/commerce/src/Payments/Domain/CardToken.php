<?php

declare(strict_types=1);

namespace Commerce\Payments\Domain;

use Tucano\SharedKernel\Privacy\DataCategory;
use Tucano\SharedKernel\Privacy\Sensitive;

/**
 * The provider's token for a card. The card number itself never reaches Tucano, and the
 * token, which still charges the card, only leaves this object for the PSP (PCI DSS).
 */
final readonly class CardToken
{
    private function __construct(private Sensitive $token) {}

    public static function of(string $value): self
    {
        if (preg_match('/^tok_[A-Za-z0-9_]{1,60}$/', $value) !== 1) {
            // Never echo it: whoever sends a card number here must not find it in a log.
            throw InvalidPayment::because('The card token is not in the format of the payment provider.');
        }

        return new self(Sensitive::of($value, DataCategory::CardToken));
    }

    public function reveal(): string
    {
        return $this->token->reveal();
    }
}
