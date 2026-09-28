<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

/** How the product cache behaves; config/catalog.php takes it from the environment. */
final readonly class ProductCacheSettings
{
    private function __construct(
        public int $ttlSeconds,
        public int $ttlJitterPercent,
        public int $missingTtlSeconds,
        public int $rebuildLockSeconds,
        public int $rebuildWaitStepMs,
        public int $rebuildWaitSteps,
    ) {}

    /**
     * A key missing from the array is a mistake in the config, and it shows at boot.
     *
     * @param array<array-key, mixed> $settings the catalog.cache config
     */
    public static function fromArray(array $settings): self
    {
        $value = static fn(string $key): int => is_numeric($settings[$key] ?? null)
            ? (int) $settings[$key]
            : throw new InvalidArgumentException(sprintf('catalog.cache.%s must be a number.', $key));

        return new self(
            $value('ttl_seconds'),
            $value('ttl_jitter_percent'),
            $value('missing_ttl_seconds'),
            $value('rebuild_lock_seconds'),
            $value('rebuild_wait_step_ms'),
            $value('rebuild_wait_steps'),
        );
    }

    public function ttl(): JitteredTtl
    {
        return new JitteredTtl($this->ttlSeconds, $this->ttlJitterPercent / 100);
    }
}
