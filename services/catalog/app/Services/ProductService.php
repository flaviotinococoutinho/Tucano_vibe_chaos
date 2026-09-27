<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ProductNotFound;
use App\Exceptions\StaleVersion;
use App\Exceptions\TransitionNotAllowed;
use App\Exceptions\UnknownCategory;
use App\Models\NewProduct;
use App\Models\Product;
use App\Models\ProductChanges;
use App\Models\ProductPage;
use App\Models\ProductStatus;
use App\Repositories\CategoryRepository;
use App\Repositories\ProductRepository;
use Exception;
use Psr\Log\LoggerInterface;
use Tucano\Messaging\Kafka\DeliveryFailed;
use Tucano\SharedKernel\Time\Clock;

/**
 * The catalog rules: what each state allows, optimistic concurrency on writes,
 * cache-aside on reads and a snapshot on Kafka after every change of a published product.
 */
final readonly class ProductService
{
    private const int REPUBLISH_BATCH = 100;

    public function __construct(
        private ProductRepository $products,
        private CategoryRepository $categories,
        private ProductCache $cache,
        private ProductPublisher $publisher,
        private Clock $clock,
        private LoggerInterface $logger,
    ) {}

    public function page(?string $category, int $page): ProductPage
    {
        if ($category !== null) {
            $this->ensureCategoryExists($category);
        }

        return $this->products->activePage($category, $page);
    }

    /** An active or discontinued product: outside the catalog, a draft does not exist. */
    public function show(string $sku): Product
    {
        $product = $this->cache->remember($sku, fn(): ?Product => $this->findPublished($sku));

        return $product ?? throw ProductNotFound::withSku($sku);
    }

    public function create(NewProduct $input): Product
    {
        $this->ensureCategoryExists($input->category);
        $product = Product::draft($input, $this->clock->now());
        $this->products->add($product);
        // A read before the product existed may have cached a "not found".
        $this->cache->forget($product->sku);

        return $product;
    }

    /** @param int|null $expectedVersion the version the client says it read (If-Match) */
    public function change(string $sku, ProductChanges $changes, ?int $expectedVersion): Product
    {
        $current = $this->find($sku);
        if ($expectedVersion !== null && $expectedVersion !== $current->version) {
            throw StaleVersion::expected($current, $expectedVersion);
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
        foreach ($this->products->published($sku, self::REPUBLISH_BATCH) as $batch) {
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
