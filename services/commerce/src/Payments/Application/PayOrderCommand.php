<?php

declare(strict_types=1);

namespace Commerce\Payments\Application;

use Commerce\Payments\Domain\CardToken;
use Commerce\Shared\Application\Idempotency\IdempotencyKey;

final readonly class PayOrderCommand
{
    public function __construct(
        public IdempotencyKey $idempotencyKey,
        public string $orderId,
        public CardToken $card,
    ) {}

    public function fingerprint(): string
    {
        // The token itself: tok_*** is every card of the provider.
        return hash('sha256', json_encode([$this->orderId, $this->card->reveal()], JSON_THROW_ON_ERROR));
    }
}
