<?php

declare(strict_types=1);

namespace Logistics\Shipping\Adapter\Driven;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Logistics\Shipping\Application\JourneyResult;
use Logistics\Shipping\Application\Port\Driven\ForRecordingJourneyChecks;
use Logistics\Shipping\Domain\Shipment\ShipmentId;

/**
 * journey_checks keeps one row per shipment. An upsert counts the rounds that
 * found the same result and remembers when that run began; a different result
 * starts a new run. Every SET reads the old row, whatever the order.
 */
final readonly class PostgresJourneyChecks implements ForRecordingJourneyChecks
{
    public function __construct(private ConnectionInterface $connection) {}

    public function record(ShipmentId $shipment, JourneyResult $result, DateTimeImmutable $at): void
    {
        $this->connection->statement(<<<'SQL'
            INSERT INTO journey_checks (shipment_id, last_result, result_since, checked_at, rounds)
            VALUES (?, ?, ?, ?, 1)
            ON CONFLICT (shipment_id) DO UPDATE SET
                rounds = CASE WHEN journey_checks.last_result = EXCLUDED.last_result THEN journey_checks.rounds + 1 ELSE 1 END,
                result_since = CASE WHEN journey_checks.last_result = EXCLUDED.last_result THEN journey_checks.result_since ELSE EXCLUDED.result_since END,
                last_result = EXCLUDED.last_result,
                checked_at = EXCLUDED.checked_at
            SQL, [$shipment->toString(), $result->value, $at->format(DATE_RFC3339_EXTENDED), $at->format(DATE_RFC3339_EXTENDED)]);
    }
}
