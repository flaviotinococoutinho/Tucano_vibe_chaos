<?php

declare(strict_types=1);

namespace Commerce\Ordering\Domain\Product;

/** Mirrors the catalog: a product is sold or it is not, never "available = false". */
enum ProductStatus: string
{
    case Active = 'active';
    case Discontinued = 'discontinued';
}
