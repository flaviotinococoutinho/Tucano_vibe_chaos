<?php

declare(strict_types=1);

namespace Tucano\SharedKernel\Documentation;

use Attribute;
use InvalidArgumentException;

/**
 * Links a use case class to its fully dressed description in docs/use-cases.
 * Architecture tests use it to make sure no use case ships undocumented.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class UseCase
{
    public function __construct(public string $id)
    {
        if (preg_match('/^UC-([A-Z]{3}-\d{2}|\d{3})$/', $id) !== 1) {
            throw new InvalidArgumentException(sprintf('"%s" is not a use case id like UC-ORD-01.', $id));
        }
    }
}
