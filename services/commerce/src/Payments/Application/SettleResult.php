<?php

declare(strict_types=1);

namespace Commerce\Payments\Application;

enum SettleResult: string
{
    case Applied = 'applied';

    /** The same event arrived again: the inbox already has it. */
    case Duplicate = 'duplicate';

    /** A new event about a payment that already moved on, a late copy of an old outcome. */
    case AlreadySettled = 'already_settled';

    /** The reference is not a payment of ours. */
    case UnknownPayment = 'unknown_payment';
}
