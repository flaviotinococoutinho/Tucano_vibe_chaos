<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\ProductStatus;
use App\Services\ProductSnapshot;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Doubles\Products;

final class ProductSnapshotTest extends TestCase
{
    #[Test]
    public function it_carries_the_full_state_of_the_product(): void
    {
        self::assertSame([
            'productId' => Products::BOOK_ID,
            'sku' => 'BOOK-DDD-001',
            'name' => 'Domain-Driven Design',
            'status' => 'active',
            'store' => 'arara',
            'category' => 'books',
            'price' => ['amount' => 18990, 'currency' => 'BRL'],
            'weightGrams' => 1100,
            'dimensions' => ['lengthMm' => 240, 'widthMm' => 170, 'heightMm' => 40],
            'version' => 3,
            'updatedAt' => '2026-09-27T12:00:04.567Z',
        ], ProductSnapshot::of(Products::book()));
    }

    #[Test]
    public function a_discontinued_product_says_so(): void
    {
        self::assertSame('discontinued', ProductSnapshot::of(Products::book(ProductStatus::Discontinued))['status']);
    }
}
