<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Product;
use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Psr\Log\LoggerInterface;
use RedisException;

/**
 * Cache-aside for single products. The cache speeds reads up but is never a
 * dependency: when Redis fails, reads go to MySQL and writes still succeed.
 *
 * @phpstan-import-type ProductRecord from Product
 */
final readonly class ProductCache
{
    private const string MISSING = 'missing';

    private JitteredTtl $ttl;

    /** @var Closure(int): void */
    private Closure $pause;

    /** @param (Closure(int): void)|null $pause waits the given milliseconds; tests replace it */
    public function __construct(
        private Repository $cache,
        private LockProvider $locks,
        private LoggerInterface $logger,
        private ProductCacheSettings $settings,
        ?Closure $pause = null,
    ) {
        $this->ttl = $settings->ttl();
        $this->pause = $pause ?? static function (int $milliseconds): void {
            usleep($milliseconds * 1_000);
        };
    }

    public static function key(string $sku): string
    {
        // Bump the version when the cached record changes shape, so a deploy never reads the old one.
        // v2 carries the store, which the reads of a store check.
        return 'product:v2:' . $sku;
    }

    public static function lockKey(string $sku): string
    {
        return 'product-rebuild:' . $sku;
    }

    /** @param Closure(): ?Product $load reads MySQL; null means there is no product to show */
    public function remember(string $sku, Closure $load): ?Product
    {
        try {
            return $this->read($sku, $load);
        } catch (RedisException $error) {
            $this->logger->warning('product cache unavailable', ['sku' => $sku, 'error' => $error->getMessage()]);

            return $load();
        }
    }

    public function forget(string $sku): void
    {
        try {
            $this->cache->forget(self::key($sku));
        } catch (RedisException $error) {
            // The write that got here already committed, and the entry expires with its TTL.
            $this->logger->warning('product cache not invalidated', ['sku' => $sku, 'error' => $error->getMessage()]);
        }
    }

    /** @param Closure(): ?Product $load */
    private function read(string $sku, Closure $load): ?Product
    {
        $entry = $this->cache->get(self::key($sku));
        if ($entry !== null) {
            $this->logger->debug('product cache hit', ['sku' => $sku]);

            return self::productIn($entry);
        }

        $this->logger->debug('product cache miss', ['sku' => $sku]);

        return $this->rebuild($sku, $load);
    }

    /**
     * Stampede protection: only the request that takes the lock reads MySQL. The
     * others wait a moment for its result and go to MySQL only if it never shows up.
     *
     * @param Closure(): ?Product $load
     */
    private function rebuild(string $sku, Closure $load): ?Product
    {
        $lock = $this->locks->lock(self::lockKey($sku), $this->settings->rebuildLockSeconds);
        if (!$lock->get()) {
            $entry = $this->awaitRebuild($sku);

            return $entry === null ? $load() : self::productIn($entry);
        }

        try {
            // The previous holder may have stored the entry between our miss and our lock.
            $entry = $this->cache->get(self::key($sku));
            if ($entry !== null) {
                return self::productIn($entry);
            }

            $product = $load();
            $this->store($sku, $product);

            return $product;
        } finally {
            $lock->release();
        }
    }

    private function awaitRebuild(string $sku): mixed
    {
        for ($step = 0; $step < $this->settings->rebuildWaitSteps; $step++) {
            ($this->pause)($this->settings->rebuildWaitStepMs);
            $entry = $this->cache->get(self::key($sku));
            if ($entry !== null) {
                return $entry;
            }
        }

        return null;
    }

    private function store(string $sku, ?Product $product): void
    {
        if ($product === null) {
            $this->cache->put(self::key($sku), self::MISSING, $this->settings->missingTtlSeconds);

            return;
        }

        $this->cache->put(self::key($sku), $product->toArray(), $this->ttl->seconds());
    }

    private static function productIn(mixed $entry): ?Product
    {
        if ($entry === self::MISSING) {
            return null;
        }

        /** @var ProductRecord $entry */
        return Product::fromArray($entry);
    }
}
