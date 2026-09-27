<?php

declare(strict_types=1);

namespace Commerce\Shared\Adapter\Driven;

use Commerce\Shared\Application\Idempotency\IdempotencyKey;
use Commerce\Shared\Application\Idempotency\IdempotencyKeyReused;
use Commerce\Shared\Application\Port\Driven\ForRememberingRequests;
use Illuminate\Database\ConnectionInterface;
use LogicException;
use Tucano\SharedKernel\Time\Clock;

/**
 * Keys live in idempotency_keys for a day. The claim is an INSERT on the
 * primary key: a concurrent request with the same key blocks on that row until
 * the first transaction ends, then reads its result (or claims the key itself
 * if the first one rolled back). An expired key can be claimed again.
 *
 * The HTTP-shaped columns come from the table design: 102 (Processing) marks a
 * claim, invisible to others until commit, and 201 is the only outcome stored,
 * because a failed request rolls its claim back and the key can be retried.
 */
final readonly class PostgresRequestMemory implements ForRememberingRequests
{
    private const string TTL = '+1 day';

    public function __construct(private ConnectionInterface $connection, private Clock $clock) {}

    public function recall(string $scope, IdempotencyKey $key, string $fingerprint): ?array
    {
        $now = $this->clock->now();
        $claimed = $this->connection->selectOne(<<<'SQL'
            INSERT INTO idempotency_keys (scope, key, request_hash, response_status, response_body, created_at, expires_at)
            VALUES (?, ?, ?, 102, '{}', ?, ?)
            ON CONFLICT (scope, key) DO UPDATE
                SET request_hash = EXCLUDED.request_hash, response_status = 102, response_body = '{}',
                    created_at = EXCLUDED.created_at, expires_at = EXCLUDED.expires_at
                WHERE idempotency_keys.expires_at <= EXCLUDED.created_at
            RETURNING key
            SQL, [$scope, $key->value, $fingerprint, $now->format(DATE_RFC3339_EXTENDED), $now->modify(self::TTL)->format(DATE_RFC3339_EXTENDED)]);
        if ($claimed !== null) {
            return null;
        }

        $stored = $this->connection->selectOne(
            'SELECT request_hash, response_body FROM idempotency_keys WHERE scope = ? AND key = ?',
            [$scope, $key->value],
        );
        if ($stored === null) {
            // The conflicting row is locked by the INSERT above, so it cannot disappear in between.
            throw new LogicException(sprintf('Idempotency key %s vanished between the claim and the read.', $key->value));
        }
        if ($stored->request_hash !== $fingerprint) {
            throw IdempotencyKeyReused::for($key);
        }

        /** @var array<string, mixed> */
        return json_decode((string) $stored->response_body, true, flags: JSON_THROW_ON_ERROR);
    }

    public function remember(string $scope, IdempotencyKey $key, array $result): void
    {
        $this->connection->update(
            'UPDATE idempotency_keys SET response_status = 201, response_body = ? WHERE scope = ? AND key = ?',
            [json_encode($result, JSON_THROW_ON_ERROR), $scope, $key->value],
        );
    }
}
