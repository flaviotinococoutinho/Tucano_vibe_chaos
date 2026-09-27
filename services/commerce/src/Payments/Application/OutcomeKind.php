<?php

declare(strict_types=1);

namespace Commerce\Payments\Application;

/** What the provider says happened, in Payments' words. */
enum OutcomeKind
{
    case Captured;
    case Failed;
    case Refunded;
}
