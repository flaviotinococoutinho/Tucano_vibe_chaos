<?php

declare(strict_types=1);

namespace Logistics\CarrierSelection\Domain;

use Tucano\SharedKernel\Domain\DomainError;
use Tucano\SharedKernel\Domain\ErrorCategory;

final class NoCarrierFits extends DomainError
{
    public static function for(Consignment $consignment): self
    {
        return new self(sprintf(
            'No carrier takes %d g from %s to %s.',
            $consignment->weightGrams,
            $consignment->originState,
            $consignment->destinationState,
        ));
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
