<?php

declare(strict_types=1);

namespace Commerce\Ordering\Application;

/** A snapshot either moves the copy forward or arrives late and changes nothing. */
enum CopyOutcome
{
    case Updated;
    case Stale;
}
