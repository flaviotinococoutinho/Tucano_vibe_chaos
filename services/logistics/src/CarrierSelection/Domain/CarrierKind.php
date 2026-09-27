<?php

declare(strict_types=1);

namespace Logistics\CarrierSelection\Domain;

/** The carriers.kind column: the Tucano fleet or a company it hires. */
enum CarrierKind: string
{
    case OwnFleet = 'own_fleet';
    case Partner = 'partner';
}
