<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\StoreNotFound;
use App\Models\Store;
use App\Repositories\StoreRepository;

/**
 * The stores the platform hosts, read straight from MySQL. There is no cache on purpose: the
 * table has a handful of rows found by a unique index, which costs about what a Redis round
 * trip costs, and the BFF keeps each store for 60 s. The hot read, a product of a store, never
 * asks for the store at all (ProductService::showInStore()).
 */
final readonly class StoreService
{
    public function __construct(private StoreRepository $stores) {}

    /** @return list<Store> */
    public function all(): array
    {
        return $this->stores->all();
    }

    public function show(string $slug): Store
    {
        return $this->stores->findBySlug($slug) ?? throw StoreNotFound::withSlug($slug);
    }
}
