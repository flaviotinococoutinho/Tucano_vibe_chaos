<?php

declare(strict_types=1);

namespace Tucano\SharedKernel\Tests\Documentation;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tucano\SharedKernel\Documentation\UseCase;

#[CoversClass(UseCase::class)]
final class UseCaseTest extends TestCase
{
    #[Test]
    public function it_accepts_context_and_summary_ids(): void
    {
        self::assertSame('UC-SHP-07', (new UseCase('UC-SHP-07'))->id);
        self::assertSame('UC-000', (new UseCase('UC-000'))->id);
    }

    #[Test]
    public function it_refuses_ids_outside_the_convention(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new UseCase('place-order');
    }
}
