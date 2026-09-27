<?php

declare(strict_types=1);

namespace App\Models;

use Tucano\SharedKernel\Money\Money;

/** A partial update: null keeps what the product already has. */
final readonly class ProductChanges
{
    public function __construct(
        public ?string $name = null,
        public ?string $category = null,
        public ?Money $price = null,
        public ?int $weightGrams = null,
        public ?Dimensions $dimensions = null,
    ) {}
}
