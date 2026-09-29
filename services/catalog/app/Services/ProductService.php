<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ProductNotFound;
use App\Exceptions\StaleVersion;
use App\Exceptions\StoreChangeNotAllowed;
use App\Exceptions\StoreNotFound;
use App\Exceptions\TransitionNotAllowed;
use App\Exceptions\UnknownCategory;
use App\Exceptions\UnknownStore;
use App\Models\NewProduct;
use App\Models\Product;
use App\Models\ProductChanges;
use App\Models\ProductPage;
use App\Models\ProductStatus;
use App\Repositories\CategoryRepository;
use App\Repositories\ProductRepository;
use App\Repositories\StoreRepository;
use Exception;
use Psr\Log\LoggerInterface;
use Tucano\Messaging\Kafka\DeliveryFailed;
use Tucano\SharedKernel\Time\Clock;

/**
 * The catalog rules: what each state allows, optimistic concurrency on writes,
 * cache-aside on reads and a snapshot on Kafka after every change of a published product.
 * Every product belongs to one store, and a store sees only its own products.
 */
final readonly class ProductService
{
    public function __construct(
        private ProductRepository $products,
        private CategoryRepository $categories,
        private StoreRepository $stores,
        private ProductCache $cache,
        private ProductPublisher $publisher,
        private Clock $clock,
        private LoggerInterface $logger,
        private int $republishBatchSize,
    ) {}

    /** The active products of every store. */
    public function page(?string $category, int $page): ProductPage
    {
        if ($category !== null) {
            $this->ensureCategoryExists($category);
        }

        return $this->products->activePage(null, $category, $page);
    }

    /** The active products of one store: the same page, narrowed to what the store sells. */
    public function storePage(string $store, ?string $category, int $page): ProductPage
    {
        if (!$this->stores->exists($store)) {
            throw StoreNotFound::withSlug($store);
        }
        if ($category !== null) {
            $this->ensureCategoryExists($category);
        }

        return $this->products->activePage($store, $category, $page);
    }

    /** An active or discontinued product: outside the catalog, a draft does not exist. */
    public function show(string $sku): Product
    {
        $product = $this->cache->remember($sku, fn(): ?Product => $this->findPublished($sku));

        return $product ?? throw ProductNotFound::withSku($sku);
    }

    /**
     * A product the store sells. The cache stays keyed by SKU and its entry carries the store,
     * so the check costs no query, and the store itself is never read: a foreign key keeps a
     * product from pointing at a store that does not exist. A product of another store is the
     * same 404 as a SKU nobody sells, and so is any SKU under an unknown store.
     */
    public function showInStore(string $store, string $sku): Product
    {
        $product = $this->show($sku);

        return $product->store === $store ? $product : throw ProductNotFound::withSku($sku);
    }

    public function create(NewProduct $input): Product
    {
        if (!$this->stores->exists($input->store)) {
            throw UnknownStore::withSlug($input->store);
        }
        $this->ensureCategoryExists($input->category);
        $product = Product::draft($input, $this->clock->now());
        $this->products->add($product);
        // A read before the product existed may have cached a "not found".
        $this->cache->forget($product->sku);

        return $product;
    }

    /**
     * @param int|null $expectedVersion the version the client says it read (If-Match)
     * @param string|null $store the store the body names: the product's own changes nothing,
     *                           and any other is refused, because a product never moves
     */
    public function change(string $sku, ProductChanges $changes, ?int $expectedVersion, ?string $store = null): Product
    {
        $current = $this->find($sku);
        if ($expectedVersion !== null && $expectedVersion !== $current->version) {
            throw StaleVersion::expected($current, $expectedVersion);
        }
        if ($store !== null && $store !== $current->store) {
            throw StoreChangeNotAllowed::of($current, $store);
        }
        if ($changes->category !== null) {
            $this->ensureCategoryExists($changes->category);
        }

        $changed = $current->revised($changes, $this->clock->now());
        if ($changed->sameStateAs($current)) {
            // Nothing to change: no new version, no event for the consumers.
            return $current;
        }

        return $this->save($current, $changed);
    }

    public function activate(string $sku): Product
    {
        return $this->moveTo($sku, ProductStatus::Active);
    }

    public function discontinue(string $sku): Product
    {
        return $this->moveTo($sku, ProductStatus::Discontinued);
    }

    /**
     * Publishes the current snapshot of every published product, or of one, again.
     * Safe to repeat: the topic is compacted and consumers keep the highest version.
     *
     * @return int how many snapshots Kafka acknowledged
     *
     * @throws DeliveryFailed
     */
    public function republish(?string $sku): int
    {
        $published = 0;
        foreach ($this->products->published($sku, $this->republishBatchSize) as $batch) {
            $this->publisher->publish(...$batch);
            $published += count($batch);
        }

        return $published;
    }

    private function moveTo(string $sku, ProductStatus $next): Product
    {
        $current = $this->find($sku);
        if (!$current->status->canMoveTo($next)) {
            throw TransitionNotAllowed::of($current, $next);
        }

        return $this->save($current, $current->movedTo($next, $this->clock->now()));
    }

    /**
     * Dual write on purpose (docs/adr/0008-transactional-outbox.md): MySQL commits
     * first and is the source of truth. If Kafka fails afterwards, the change stands,
     * the gap goes to the log and catalog:republish closes it.
     */
    private function save(Product $current, Product $changed): Product
    {
        if (!$this->products->update($changed, $current->version)) {
            throw StaleVersion::changedMeanwhile($current);
        }
        $this->cache->forget($changed->sku);
        if ($changed->status->isPublished()) {
            $this->publish($changed);
        }

        return $changed;
    }

    private function publish(Product $product): void
    {
        try {
            $this->publisher->publish($product);
        } catch (Exception $error) {
            // DeliveryFailed or any librdkafka error: the committed write must not turn into a 500.
            $this->logger->warning('product snapshot not published', [
                'product_id' => $product->id->toString(),
                'sku' => $product->sku,
                'version' => $product->version,
                'error' => $error->getMessage(),
            ]);
        }
    }

    private function find(string $sku): Product
    {
        return $this->products->findBySku($sku) ?? throw ProductNotFound::withSku($sku);
    }

    private function findPublished(string $sku): ?Product
    {
        $product = $this->products->findBySku($sku);

        return $product !== null && $product->status->isPublished() ? $product : null;
    }

    private function ensureCategoryExists(string $slug): void
    {
        if (!$this->categories->exists($slug)) {
            throw UnknownCategory::withSlug($slug);
        }
    }
}
