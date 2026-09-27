<?php

declare(strict_types=1);

namespace App\Models;

use JsonSerializable;

final readonly class ProductPage implements JsonSerializable
{
    public const int SIZE = 20;

    /** @param list<Product> $products */
    public function __construct(
        public array $products,
        public int $page,
        public int $total,
    ) {}

    /** @return array{data: list<Product>, page: int, perPage: int, total: int} */
    public function jsonSerialize(): array
    {
        return ['data' => $this->products, 'page' => $this->page, 'perPage' => self::SIZE, 'total' => $this->total];
    }
}
