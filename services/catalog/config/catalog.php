<?php

declare(strict_types=1);

return [
    // Cache-aside for single products (ProductCache).
    'cache' => [
        'ttl_seconds' => (int) env('CATALOG_CACHE_TTL_SECONDS', 300),
        // Each entry lives the TTL give or take this share, so entries written together do not expire together.
        'ttl_jitter_percent' => (int) env('CATALOG_CACHE_TTL_JITTER_PERCENT', 10),
        // Short, so a product activated right after a 404 does not stay hidden for long.
        'missing_ttl_seconds' => (int) env('CATALOG_CACHE_MISSING_TTL_SECONDS', 30),
        // Longer than a rebuild takes. If the holder dies, the lock expires on its own.
        'rebuild_lock_seconds' => (int) env('CATALOG_CACHE_REBUILD_LOCK_SECONDS', 5),
        // Who finds the lock taken looks again this many times, this far apart, before reading MySQL itself.
        'rebuild_wait_step_ms' => (int) env('CATALOG_CACHE_REBUILD_WAIT_STEP_MS', 50),
        'rebuild_wait_steps' => (int) env('CATALOG_CACHE_REBUILD_WAIT_STEPS', 10),
    ],
    // Products per page of GET /v1/products; the answer says it in perPage.
    'page_size' => (int) env('CATALOG_PAGE_SIZE', 20),
    // catalog:republish sends the snapshots of the active products in batches of this size.
    'republish_batch_size' => (int) env('CATALOG_REPUBLISH_BATCH_SIZE', 100),
];
