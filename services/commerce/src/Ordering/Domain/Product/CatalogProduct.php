<?php

declare(strict_types=1);

namespace Commerce\Ordering\Domain\Product;

use Commerce\Ordering\Domain\Error\ProductUnavailable;
use Commerce\Ordering\Domain\Store\StoreSlug;
use Tucano\SharedKernel\Money\Money;

/** What Ordering knows about a product: the local snapshot of the catalog. */
final readonly class CatalogProduct
{
    private function __construct(
        public Sku $sku,
        public string $name,
        public Money $price,
        public ProductStatus $status,
        /** Null while the copy only has a snapshot from before the stores (ADR 0031). */
        private ?StoreSlug $store,
    ) {}

    public static function of(Sku $sku, string $name, Money $price, ProductStatus $status, ?StoreSlug $store): self
    {
        return new self($sku, $name, $price, $status, $store);
    }

    /** Every product belongs to exactly one store; one whose store the copy does not know yet belongs to none. */
    public function belongsTo(StoreSlug $store): bool
    {
        return $this->store !== null && $this->store->equals($store);
    }

    public function assertSellable(): void
    {
        if ($this->status === ProductStatus::Discontinued) {
            throw ProductUnavailable::discontinued($this->sku);
        }
    }
}
