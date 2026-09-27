<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Life cycle of a product (docs/adr/0011-state-machines-without-flags.md). A draft
 * never leaves the catalog: the API does not show it and Kafka never carries it.
 */
enum ProductStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Discontinued = 'discontinued';

    /**
     * The transition table. A missing case fails the static analysis and, at
     * runtime, throws UnhandledMatchError.
     *
     * @return list<self>
     */
    public function nextStates(): array
    {
        return match ($this) {
            self::Draft => [self::Active],
            self::Active => [self::Discontinued],
            self::Discontinued => [self::Active],
        };
    }

    public function canMoveTo(self $next): bool
    {
        return in_array($next, $this->nextStates(), true);
    }

    public function isPublished(): bool
    {
        return $this !== self::Draft;
    }
}
