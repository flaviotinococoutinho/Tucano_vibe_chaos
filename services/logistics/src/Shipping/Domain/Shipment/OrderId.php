<?php

declare(strict_types=1);

namespace Logistics\Shipping\Domain\Shipment;

use Tucano\SharedKernel\Identity\UuidIdentifier;

/** Logistics does not know orders; it keeps only the id of the one a shipment serves. */
final readonly class OrderId extends UuidIdentifier {}
