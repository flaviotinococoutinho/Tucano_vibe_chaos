<?php

declare(strict_types=1);

namespace Tucano\SharedKernel\Domain;

use Attribute;
use ReflectionClass;

/**
 * The name clients know a domain error by, for the errors whose category is not enough
 * to tell them apart: two conflicts that ask for different answers, like a product out
 * of line and a stock that ran out. The HTTP adapters turn the name into the RFC 9457
 * type URI, which lands on the page that explains the problem.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class ProblemType
{
    public function __construct(public string $name) {}

    /** The name the error declares, or null when its category says it all. */
    public static function of(object $error): ?string
    {
        $declared = (new ReflectionClass($error))->getAttributes(self::class)[0] ?? null;

        return $declared?->newInstance()->name;
    }
}
