<?php

declare(strict_types=1);

namespace Tucano\SharedKernel\Tests\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tucano\SharedKernel\Domain\DomainError;
use Tucano\SharedKernel\Domain\ErrorCategory;

#[CoversClass(DomainError::class)]
final class DomainErrorTest extends TestCase
{
    #[Test]
    public function domain_errors_carry_a_business_category(): void
    {
        $error = new class ('Only 2 units of BOOK-DDD-001 left.') extends DomainError {
            public function category(): ErrorCategory
            {
                return ErrorCategory::Conflict;
            }
        };

        self::assertSame(ErrorCategory::Conflict, $error->category());
        self::assertSame('Only 2 units of BOOK-DDD-001 left.', $error->getMessage());
    }
}
