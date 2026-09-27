<?php

declare(strict_types=1);

namespace Commerce\Inventory\Domain;

use Tucano\SharedKernel\Domain\DomainError;
use Tucano\SharedKernel\Domain\ErrorCategory;

final class InsufficientStock extends DomainError
{
    /** @param array<string, list<string>> $shortSkusByCenter */
    public static function in(array $shortSkusByCenter): self
    {
        if ($shortSkusByCenter === []) {
            return new self('No fulfillment center carries these items.');
        }
        $parts = [];
        foreach ($shortSkusByCenter as $center => $skus) {
            $parts[] = sprintf('%s is short of %s', $center, implode(', ', $skus));
        }

        return new self(sprintf('Not enough stock: %s.', implode('; ', $parts)));
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
