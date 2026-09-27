<?php

declare(strict_types=1);

namespace Commerce\Payments\Application;

/** Where a charge stands at the provider, in Payments' words. */
enum ChargeState
{
    case Processing;
    case Succeeded;
    case Failed;

    /** Succeeded, and a refund of it is being processed. */
    case Refunding;

    case Refunded;
}
