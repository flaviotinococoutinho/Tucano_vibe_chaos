<?php

declare(strict_types=1);

namespace Commerce\Shared\Application\Port\Driven;

use Commerce\Shared\Application\Idempotency\IdempotencyKey;
use Commerce\Shared\Application\Idempotency\IdempotencyKeyReused;

/**
 * Idempotency for commands that create something. Both calls run inside the
 * transaction of the command, so the key and the result commit together.
 */
interface ForRememberingRequests
{
    /**
     * Returns the result stored for the key, or claims the key for this request and returns null.
     * A second request with the same key waits until the first one commits or rolls back.
     *
     * @return array<string, mixed>|null
     *
     * @throws IdempotencyKeyReused when the key was used for a different request
     */
    public function recall(string $scope, IdempotencyKey $key, string $fingerprint): ?array;

    /** @param array<string, mixed> $result */
    public function remember(string $scope, IdempotencyKey $key, array $result): void;
}
