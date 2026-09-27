<?php

declare(strict_types=1);

namespace Commerce\Payments\Application\Port\Driving;

use Commerce\Payments\Application\PaymentAttempt;
use Commerce\Payments\Application\PayOrderCommand;
use Commerce\Payments\Domain\GatewayUnavailable;
use Commerce\Payments\Domain\OrderNotPayable;

interface ForPayingOrders
{
    /**
     * Sends the charge and returns at once: the outcome arrives later by webhook (UC-PAY-02).
     *
     * @throws OrderNotPayable
     * @throws GatewayUnavailable when the circuit breaker is open; nothing was sent
     */
    public function payOrder(PayOrderCommand $command): PaymentAttempt;
}
