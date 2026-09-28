<?php

declare(strict_types=1);

namespace Logistics\Shipping\Application\Port\Driven;

use DateTimeImmutable;
use Logistics\Shipping\Domain\Error\ShipmentChangedMeanwhile;
use Logistics\Shipping\Domain\Shipment\OrderId;
use Logistics\Shipping\Domain\Shipment\Shipment;
use Logistics\Shipping\Domain\Shipment\ShipmentId;
use Logistics\Shipping\Domain\Shipment\ShipmentReference;
use Logistics\Shipping\Domain\Shipment\TrackingCode;

interface ForStoringShipments
{
    public function add(Shipment $shipment): void;

    /**
     * The shipment of the order, or null when the order has none. It stays
     * locked until the transaction ends, so a concurrent change waits for this
     * one instead of failing on the version.
     */
    public function forOrder(OrderId $order): ?Shipment;

    /** Like forOrder(), by the shipment's own id. */
    public function withId(ShipmentId $shipment): ?Shipment;

    /** Like forOrder(), by the code on the label, which is how carriers know the shipment. */
    public function withTrackingCode(TrackingCode $trackingCode): ?Shipment;

    /** @throws ShipmentChangedMeanwhile when the stored version is not the one this shipment was loaded with */
    public function save(Shipment $shipment): void;

    /**
     * Claims the shipment in the hands of a carrier that has gone without news
     * for the longest, if that is since before the given instant, and touches it
     * so the next rounds leave it alone until it is quiet again.
     */
    public function claimQuiet(DateTimeImmutable $quietSince, DateTimeImmutable $now): ?ShipmentReference;
}
