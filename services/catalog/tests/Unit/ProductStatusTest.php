<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\ProductStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProductStatusTest extends TestCase
{
    /** @return iterable<string, array{ProductStatus, ProductStatus, bool}> every pair of states */
    public static function moves(): iterable
    {
        yield 'a draft goes on sale' => [ProductStatus::Draft, ProductStatus::Active, true];
        yield 'a draft cannot be discontinued' => [ProductStatus::Draft, ProductStatus::Discontinued, false];
        yield 'a draft cannot move to draft' => [ProductStatus::Draft, ProductStatus::Draft, false];
        yield 'an active product is discontinued' => [ProductStatus::Active, ProductStatus::Discontinued, true];
        yield 'an active product never goes back to draft' => [ProductStatus::Active, ProductStatus::Draft, false];
        yield 'an active product cannot be activated again' => [ProductStatus::Active, ProductStatus::Active, false];
        yield 'a discontinued product comes back' => [ProductStatus::Discontinued, ProductStatus::Active, true];
        yield 'a discontinued product never goes back to draft' => [ProductStatus::Discontinued, ProductStatus::Draft, false];
        yield 'a discontinued product cannot be discontinued again' => [ProductStatus::Discontinued, ProductStatus::Discontinued, false];
    }

    #[Test]
    #[DataProvider('moves')]
    public function the_transition_table_decides_every_move(ProductStatus $from, ProductStatus $to, bool $allowed): void
    {
        self::assertSame($allowed, $from->canMoveTo($to));
    }

    #[Test]
    public function only_drafts_stay_inside_the_catalog(): void
    {
        self::assertFalse(ProductStatus::Draft->isPublished());
        self::assertTrue(ProductStatus::Active->isPublished());
        self::assertTrue(ProductStatus::Discontinued->isPublished());
    }
}
