<?php

declare(strict_types=1);

namespace Commerce\Ordering\Application;

/** What the order made of a captured payment. */
enum PaymentSettlement
{
    case Paid;

    /** The order stopped waiting first (its reservation ran out): the money has to go back. */
    case TooLate;
}
