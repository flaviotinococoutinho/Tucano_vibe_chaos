<?php

declare(strict_types=1);

namespace Commerce\Inventory\Application\UseCase;

use Commerce\Inventory\Application\Port\Driven\ForChoosingStrategy;
use Commerce\Inventory\Application\Port\Driven\ForFindingFulfillmentCenters;
use Commerce\Inventory\Application\Port\Driven\ForHoldingStock;
use Commerce\Inventory\Application\Port\Driven\ForRecordingReservations;
use Commerce\Inventory\Application\Port\Driving\ForReservingStock;
use Commerce\Inventory\Application\ReservedStock;
use Commerce\Inventory\Application\StockRequest;
use Commerce\Inventory\Domain\FulfillmentCenter;
use Commerce\Inventory\Domain\InsufficientStock;
use Commerce\Shared\Application\Isolation;
use Commerce\Shared\Application\Port\Driven\ForRunningTransactions;
use Tucano\SharedKernel\Documentation\UseCase;

#[UseCase('UC-INV-01')]
final readonly class ReserveStock implements ForReservingStock
{
    public function __construct(
        private ForRunningTransactions $transactions,
        private ForFindingFulfillmentCenters $centers,
        private ForHoldingStock $stock,
        private ForRecordingReservations $reservations,
        private ForChoosingStrategy $strategies,
    ) {}

    public function requiredIsolation(): Isolation
    {
        return $this->strategies->current()->isolation();
    }

    public function reserve(StockRequest $request): ReservedStock
    {
        $shortages = [];
        foreach ($this->centers->all()->sameStateFirst($request->destinationState) as $center) {
            try {
                // One savepoint per center: a center that cannot fill the whole order keeps no hold.
                return $this->transactions->run(fn(): ReservedStock => $this->reserveIn($center, $request));
            } catch (CenterFallsShort $shortage) {
                $shortages[$center->code] = $shortage->skus;
            }
        }

        throw InsufficientStock::in($shortages);
    }

    private function reserveIn(FulfillmentCenter $center, StockRequest $request): ReservedStock
    {
        $short = [];
        foreach ($request->items as $item) {
            if (!$this->stock->hold($center->code, $item->sku, $item->quantity)) {
                $short[] = $item->sku;
            }
        }
        if ($short !== []) {
            throw new CenterFallsShort($short);
        }
        $this->reservations->record($request->orderId, $center->code, $request->items, $request->expiresAt);

        return new ReservedStock($center->code);
    }
}
