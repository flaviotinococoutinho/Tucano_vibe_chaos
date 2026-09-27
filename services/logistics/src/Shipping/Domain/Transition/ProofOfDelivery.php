<?php

declare(strict_types=1);

namespace Logistics\Shipping\Domain\Transition;

use Logistics\Shipping\Domain\Error\InvalidShipment;

/** Who received the shipment and the document they showed. */
final readonly class ProofOfDelivery
{
    private const int MAX_NAME = 120;

    private const int MAX_DOCUMENT = 20;

    private function __construct(public string $receiverName, public string $receiverDocument) {}

    public static function of(string $receiverName, string $receiverDocument): self
    {
        $receiverName = trim($receiverName);
        $receiverDocument = trim($receiverDocument);
        if ($receiverName === '' || mb_strlen($receiverName) > self::MAX_NAME) {
            throw InvalidShipment::because(sprintf('The receiver name takes 1 to %d characters.', self::MAX_NAME));
        }
        if ($receiverDocument === '' || mb_strlen($receiverDocument) > self::MAX_DOCUMENT) {
            throw InvalidShipment::because(sprintf('The receiver document takes 1 to %d characters.', self::MAX_DOCUMENT));
        }

        return new self($receiverName, $receiverDocument);
    }
}
