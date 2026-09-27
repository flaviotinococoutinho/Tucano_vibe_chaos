<?php

declare(strict_types=1);

namespace Logistics\Shipping\Application;

/** A snapshot either moves the copy forward or arrives late and changes nothing. */
enum CopyOutcome
{
    case Updated;
    case Stale;
}
