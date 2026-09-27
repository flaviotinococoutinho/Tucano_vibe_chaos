<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory;

use Commerce\Inventory\Domain\FulfillmentCenter;
use Commerce\Inventory\Domain\FulfillmentCenters;
use Commerce\Inventory\Domain\InsufficientStock;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FulfillmentCentersTest extends TestCase
{
    #[Test]
    public function the_center_in_the_destination_state_is_tried_first(): void
    {
        $centers = new FulfillmentCenters(new FulfillmentCenter('BHZ1', 'MG'), new FulfillmentCenter('GRU1', 'SP'), new FulfillmentCenter('CWB1', 'PR'));

        self::assertSame(['GRU1', 'BHZ1', 'CWB1'], self::codes($centers->sameStateFirst('SP')));
        self::assertSame(['BHZ1', 'CWB1', 'GRU1'], self::codes($centers->sameStateFirst('MG')));
        self::assertSame(['BHZ1', 'CWB1', 'GRU1'], self::codes($centers->sameStateFirst('BA')));
    }

    #[Test]
    public function a_shortage_says_what_each_center_lacks(): void
    {
        $shortage = InsufficientStock::in(['GRU1' => ['ELEC-MON-027'], 'BHZ1' => ['ELEC-MON-027', 'HOME-CHAIR-001']]);

        self::assertSame('Not enough stock: GRU1 is short of ELEC-MON-027; BHZ1 is short of ELEC-MON-027, HOME-CHAIR-001.', $shortage->getMessage());
    }

    /**
     * @param list<FulfillmentCenter> $centers
     *
     * @return list<string>
     */
    private static function codes(array $centers): array
    {
        return array_map(static fn(FulfillmentCenter $center): string => $center->code, $centers);
    }
}
