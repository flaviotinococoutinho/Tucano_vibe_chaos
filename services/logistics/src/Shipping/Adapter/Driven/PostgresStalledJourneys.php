<?php

declare(strict_types=1);

namespace Logistics\Shipping\Adapter\Driven;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Logistics\Shipping\Application\JourneyResult;
use Logistics\Shipping\Application\Port\Driven\ForFindingStalledJourneys;
use Logistics\Shipping\Application\StalledJourney;
use Logistics\Shipping\Application\StalledJourneys;
use Logistics\Shipping\Domain\Shipment\TrackingCode;
use stdClass;
use Tucano\SharedKernel\Identity\Snowflake\Snowflake;

/**
 * The analytical read of UC-SHP-13, in a read-only transaction with its own
 * statement_timeout: it shares the primary with the webhooks, and a slow
 * report must give up before it gets in their way (in production it would go
 * to a replica). The last step comes from shipment_transitions, the history,
 * and not from shipments.updated_at, which the reconciliation touches on every
 * claim. The window count comes before the LIMIT, so the alert knows how many
 * there are beyond the ones it lists.
 */
final readonly class PostgresStalledJourneys implements ForFindingStalledJourneys
{
    public function __construct(private ConnectionInterface $connection, private int $statementTimeoutMs) {}

    public function quietSince(DateTimeImmutable $before, int $limit): StalledJourneys
    {
        $rows = $this->connection->transaction(function () use ($before, $limit): array {
            $this->connection->statement('SET TRANSACTION READ ONLY');
            // SET takes no bind parameters; the int cast keeps it a number.
            $this->connection->statement(sprintf('SET LOCAL statement_timeout = %d', $this->statementTimeoutMs));

            // The statuses are the ones of ShipmentStatus::awaitsCarrier().
            return $this->connection->select(<<<'SQL'
                SELECT s.tracking_code, s.status, s.carrier_code, last_step.at AS last_step_at,
                       c.last_result, c.result_since, count(*) OVER () AS total
                  FROM shipments s
                 CROSS JOIN LATERAL (SELECT max(t.occurred_at) AS at FROM shipment_transitions t WHERE t.shipment_id = s.id) AS last_step
                  LEFT JOIN journey_checks c ON c.shipment_id = s.id
                 WHERE s.status IN ('ready_for_pickup', 'picked_up', 'in_transit', 'out_for_delivery', 'delivery_failed', 'returning')
                   AND last_step.at <= ?
                 ORDER BY last_step.at
                 LIMIT ?
                SQL, [$before->format(DATE_RFC3339_EXTENDED), $limit]);
        });

        return StalledJourneys::of(
            array_values(array_map(self::journeyOf(...), array_filter($rows, static fn(mixed $row): bool => $row instanceof stdClass))),
            $rows === [] || !$rows[0] instanceof stdClass ? 0 : (int) $rows[0]->total,
            $before,
        );
    }

    private static function journeyOf(stdClass $row): StalledJourney
    {
        return StalledJourney::of(
            (string) TrackingCode::fromSnowflake(Snowflake::fromInt((int) $row->tracking_code)),
            (string) $row->status,
            (string) $row->carrier_code,
            new DateTimeImmutable((string) $row->last_step_at),
            $row->last_result === null ? null : JourneyResult::from((string) $row->last_result),
            $row->result_since === null ? null : new DateTimeImmutable((string) $row->result_since),
        );
    }
}
