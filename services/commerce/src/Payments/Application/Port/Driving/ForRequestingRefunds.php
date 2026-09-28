<?php

declare(strict_types=1);

namespace Commerce\Payments\Application\Port\Driving;

use Commerce\Payments\Application\RefundRequest;

interface ForRequestingRefunds
{
    /** Marks the captured payment of the order for a refund; the provider hears about it from the reconciliation. */
    public function refundOrder(string $orderId): RefundRequest;
}
