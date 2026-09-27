<?php

declare(strict_types=1);

namespace Tests\Doubles;

use App\Models\Dimensions;
use App\Models\Product;
use App\Models\ProductId;
use App\Models\ProductStatus;
use DateTimeImmutable;
use Tucano\SharedKernel\Money\Currency;
use Tucano\SharedKernel\Money\Money;

/** Products built in memory, for tests that do not need MySQL. */
final class Products
{
    public const string BOOK_ID = '01926f3a-8c1e-7b2d-9f4a-3c5e6d7f8a9b';

    public static function book(ProductStatus $status = ProductStatus::Active): Product
    {
        return new Product(
            ProductId::fromString(self::BOOK_ID),
            'BOOK-DDD-001',
            'Domain-Driven Design',
            $status,
            'books',
            Money::of(18990, Currency::brl()),
            1100,
            new Dimensions(240, 170, 40),
            3,
            new DateTimeImmutable('2026-09-27T12:00:04.567891Z'),
        );
    }
}
