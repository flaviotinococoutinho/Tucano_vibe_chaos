<?php

declare(strict_types=1);

namespace Logistics\Shipping\Domain\Transition;

/** Why a visit to the address did not end in a delivery. */
enum DeliveryFailure: string
{
    case RecipientAbsent = 'recipient_absent';
    case AddressNotFound = 'address_not_found';
    case RecipientRefused = 'recipient_refused';

    /** A refusal is reason enough to send the shipment back; the other failures only after the last attempt. */
    public function justifiesReturn(): bool
    {
        return match ($this) {
            self::RecipientRefused => true,
            self::RecipientAbsent, self::AddressNotFound => false,
        };
    }
}
