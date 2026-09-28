<?php

declare(strict_types=1);

namespace Logistics\Shipping\Application;

enum LabelOutcome: string
{
    /** The label is stored and the shipment waits for pickup with it. */
    case Attached = 'attached';

    /** The shipment already left created (it has its label, or it was cancelled): nothing to do. */
    case NotNeeded = 'not_needed';
}
