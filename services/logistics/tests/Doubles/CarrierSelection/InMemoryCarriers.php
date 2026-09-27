<?php

declare(strict_types=1);

namespace Tests\Doubles\CarrierSelection;

use Logistics\CarrierSelection\Application\Port\Driven\ForFindingCarriers;
use Logistics\CarrierSelection\Domain\Carrier;
use Logistics\CarrierSelection\Domain\CarrierKind;
use Logistics\CarrierSelection\Domain\Carriers;

/** The carriers of database/seeders/CarrierSeeder, without the database. */
final readonly class InMemoryCarriers implements ForFindingCarriers
{
    public static function seeded(): Carriers
    {
        return new Carriers(
            new Carrier('tucano-express', CarrierKind::OwnFleet, 20_000),
            new Carrier('ligeirinho', CarrierKind::Partner, 30_000),
            new Carrier('correio-nacional', CarrierKind::Partner, 30_000),
            new Carrier('carga-pesada', CarrierKind::Partner, 1_000_000),
        );
    }

    public function all(): Carriers
    {
        return self::seeded();
    }
}
