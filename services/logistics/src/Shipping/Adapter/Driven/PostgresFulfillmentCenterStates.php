<?php

declare(strict_types=1);

namespace Logistics\Shipping\Adapter\Driven;

use Illuminate\Database\ConnectionInterface;
use Logistics\Shipping\Application\Port\Driven\ForLocatingFulfillmentCenters;
use Logistics\Shipping\Domain\Error\InvalidShipment;
use Logistics\Shipping\Domain\Shipment\FulfillmentCenterCode;
use Tucano\SharedKernel\Address\BrazilianState;

final readonly class PostgresFulfillmentCenterStates implements ForLocatingFulfillmentCenters
{
    public function __construct(private ConnectionInterface $connection) {}

    public function stateOf(FulfillmentCenterCode $center): BrazilianState
    {
        $state = $this->connection->scalar('SELECT state FROM fulfillment_centers WHERE code = ?', [(string) $center]);

        return is_string($state) ? BrazilianState::from($state) : throw InvalidShipment::because(sprintf('Fulfillment center %s is not in the fulfillment_centers table.', $center));
    }
}
