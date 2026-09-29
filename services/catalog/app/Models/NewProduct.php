<?php

declare(strict_types=1);

namespace App\Models;

use Tucano\SharedKernel\Money\Money;

/** What it takes to create a product. It always starts as a draft, in the store that will sell it. */
final readonly class NewProduct
{
    public function __construct(
        public string $sku,
        public string $name,
        public string $store,
        public string $category,
        public Money $price,
        public int $weightGrams,
        public Dimensions $dimensions,
    ) {}
}
