<?php

declare(strict_types=1);

namespace Logistics\CarrierSelection\Adapter\Driven;

use Illuminate\Database\ConnectionInterface;
use Logistics\CarrierSelection\Application\Port\Driven\ForFindingCarriers;
use Logistics\CarrierSelection\Domain\Carrier;
use Logistics\CarrierSelection\Domain\CarrierKind;
use Logistics\CarrierSelection\Domain\Carriers;
use stdClass;

/** The carriers table is reference data; the rules that pick from it live in code. */
final readonly class PostgresCarriers implements ForFindingCarriers
{
    public function __construct(private ConnectionInterface $connection) {}

    public function all(): Carriers
    {
        $rows = $this->connection->select('SELECT code, kind, max_weight_grams FROM carriers ORDER BY code');

        return new Carriers(...array_map(
            static fn(stdClass $row): Carrier => new Carrier((string) $row->code, CarrierKind::from((string) $row->kind), (int) $row->max_weight_grams),
            $rows,
        ));
    }
}
