<?php

declare(strict_types=1);

namespace Commerce\Payments\Application;

use Tucano\SharedKernel\Money\Money;

/** Payments' view of an order: which one, and how much it costs. */
final readonly class PayableOrder
{
    public function __construct(public string $orderId, public Money $amountDue) {}
}
