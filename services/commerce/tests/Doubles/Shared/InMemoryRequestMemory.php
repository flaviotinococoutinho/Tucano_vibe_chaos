<?php

declare(strict_types=1);

namespace Tests\Doubles\Shared;

use Commerce\Shared\Application\Idempotency\IdempotencyKey;
use Commerce\Shared\Application\Idempotency\IdempotencyKeyReused;
use Commerce\Shared\Application\Port\Driven\ForRememberingRequests;

final class InMemoryRequestMemory implements ForRememberingRequests
{
    /** @var array<string, array{fingerprint: string, result: array<string, mixed>|null}> */
    private array $keys = [];

    public function recall(string $scope, IdempotencyKey $key, string $fingerprint): ?array
    {
        $id = $scope . ' ' . $key->value;
        if (!isset($this->keys[$id])) {
            $this->keys[$id] = ['fingerprint' => $fingerprint, 'result' => null];

            return null;
        }
        if ($this->keys[$id]['fingerprint'] !== $fingerprint) {
            throw IdempotencyKeyReused::for($key);
        }

        return $this->keys[$id]['result'];
    }

    public function remember(string $scope, IdempotencyKey $key, array $result): void
    {
        $this->keys[$scope . ' ' . $key->value]['result'] = $result;
    }
}
