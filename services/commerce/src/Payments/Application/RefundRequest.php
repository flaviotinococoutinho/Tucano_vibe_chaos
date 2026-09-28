<?php

declare(strict_types=1);

namespace Commerce\Payments\Application;

enum RefundRequest: string
{
    /** The captured payment of the order is now refund_requested; the reconciliation sends it (UC-PAY-04). */
    case Requested = 'requested';

    /** The order has no captured payment left to give back: it was never captured, or it is already on its way back. */
    case NothingToRefund = 'nothing_to_refund';
}
