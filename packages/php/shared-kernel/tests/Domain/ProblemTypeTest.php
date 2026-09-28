<?php

declare(strict_types=1);

namespace Tucano\SharedKernel\Tests\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tucano\SharedKernel\Domain\DomainError;
use Tucano\SharedKernel\Domain\ErrorCategory;
use Tucano\SharedKernel\Domain\ProblemType;

#[CoversClass(ProblemType::class)]
final class ProblemTypeTest extends TestCase
{
    #[Test]
    public function an_error_says_the_name_clients_know_it_by(): void
    {
        $outOfLine = new #[ProblemType('product-unavailable')] class ('BOOK-OLD-001 is out of line.') extends DomainError {
            public function category(): ErrorCategory
            {
                return ErrorCategory::Conflict;
            }
        };

        self::assertSame('product-unavailable', ProblemType::of($outOfLine));
    }

    #[Test]
    public function an_error_without_a_name_leaves_it_to_its_category(): void
    {
        $plain = new class ('Not found.') extends DomainError {
            public function category(): ErrorCategory
            {
                return ErrorCategory::NotFound;
            }
        };

        self::assertNull(ProblemType::of($plain));
    }
}
