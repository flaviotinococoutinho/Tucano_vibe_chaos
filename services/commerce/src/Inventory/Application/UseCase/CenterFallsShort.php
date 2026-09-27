<?php

declare(strict_types=1);

namespace Commerce\Inventory\Application\UseCase;

use RuntimeException;

/**
 * @internal Thrown inside the savepoint of one center, so the holds already
 * taken there roll back before the next center is tried.
 */
final class CenterFallsShort extends RuntimeException
{
    /** @param list<string> $skus */
    public function __construct(public readonly array $skus)
    {
        parent::__construct(sprintf('Short of %s.', implode(', ', $skus)));
    }
}
