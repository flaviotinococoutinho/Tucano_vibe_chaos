<?php

declare(strict_types=1);

namespace Tests\Doubles\Shipping;

use Logistics\Shipping\Application\CancellationOutcome;
use Logistics\Shipping\Application\CancelledOrder;
use Logistics\Shipping\Application\CreatedShipment;
use Logistics\Shipping\Application\PaidOrder;
use Logistics\Shipping\Application\Port\Driving\ForCancellingShipments;
use Logistics\Shipping\Application\Port\Driving\ForCreatingShipments;
use Logistics\Shipping\Domain\Shipment\CarrierCode;
use Logistics\Shipping\Domain\Shipment\ShipmentId;
use Logistics\Shipping\Domain\Shipment\ShipmentReference;
use Logistics\Shipping\Domain\Shipment\TrackingCode;
use Tucano\SharedKernel\Domain\DomainError;
use Tucano\SharedKernel\Identity\Snowflake\NodeId;
use Tucano\SharedKernel\Identity\Snowflake\Snowflake;

/** Both driving ports of the order intake: keeps what the handler asked, or refuses the way the test says. */
final class RecordedShipmentRequests implements ForCreatingShipments, ForCancellingShipments
{
    /** @var list<PaidOrder> */
    public private(set) array $paid = [];

    /** @var list<CancelledOrder> */
    public private(set) array $cancelled = [];

    private ?DomainError $refusal = null;

    public function refuseWith(DomainError $refusal): void
    {
        $this->refusal = $refusal;
    }

    public function create(PaidOrder $order): CreatedShipment
    {
        $this->refuseIfTold();
        $this->paid[] = $order;

        return new CreatedShipment(
            ShipmentReference::of(ShipmentId::generate(), TrackingCode::fromSnowflake(Snowflake::compose(1_790_510_400_000, new NodeId(1, 12), 0)), $order->orderId),
            CarrierCode::of('tucano-express'),
        );
    }

    public function cancel(CancelledOrder $order): CancellationOutcome
    {
        $this->refuseIfTold();
        $this->cancelled[] = $order;

        return CancellationOutcome::Cancelled;
    }

    private function refuseIfTold(): void
    {
        if ($this->refusal !== null) {
            throw $this->refusal;
        }
    }
}
