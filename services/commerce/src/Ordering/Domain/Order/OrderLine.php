<?php

declare(strict_types=1);

namespace Commerce\Ordering\Domain\Order;

use Commerce\Ordering\Domain\Product\CatalogProduct;
use Commerce\Ordering\Domain\Product\Sku;
use Tucano\SharedKernel\Money\Money;

/** A product, how many, and the price frozen at the moment of purchase. */
final readonly class OrderLine
{
    public function __construct(
        public Sku $sku,
        public string $productName,
        public Quantity $quantity,
        public Money $unitPrice,
    ) {}

    public static function of(CatalogProduct $product, Quantity $quantity): self
    {
        $product->assertSellable();

        return new self($product->sku, $product->name, $quantity, $product->price);
    }

    public function subtotal(): Money
    {
        return $this->unitPrice->multiply($this->quantity->value);
    }
}
