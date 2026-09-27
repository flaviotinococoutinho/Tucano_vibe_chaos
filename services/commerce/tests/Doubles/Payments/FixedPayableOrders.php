<?php

declare(strict_types=1);

namespace Tests\Doubles\Payments;

use Commerce\Payments\Application\PayableOrder;
use Commerce\Payments\Application\Port\Driven\ForFindingPayableOrders;
use Commerce\Payments\Domain\OrderNotPayable;
use Tucano\SharedKernel\Money\Money;

final readonly class FixedPayableOrders implements ForFindingPayableOrders
{
    /** @param array<string, Money> $amountsDue order id => amount */
    public function __construct(private array $amountsDue) {}

    public function payable(string $orderId): PayableOrder
    {
        $amount = $this->amountsDue[$orderId] ?? throw OrderNotPayable::because($orderId, 'it is paid');

        return new PayableOrder($orderId, $amount);
    }

    public function awaitsPayment(string $orderId): bool
    {
        return isset($this->amountsDue[$orderId]);
    }
}
