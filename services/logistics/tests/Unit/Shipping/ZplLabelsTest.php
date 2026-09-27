<?php

declare(strict_types=1);

namespace Tests\Unit\Shipping;

use DateTimeImmutable;
use Logistics\Shipping\Adapter\Driven\ZplLabels;
use Logistics\Shipping\Domain\Parcel\Parcels;
use Logistics\Shipping\Domain\Shipment\CarrierCode;
use Logistics\Shipping\Domain\Shipment\FulfillmentCenterCode;
use Logistics\Shipping\Domain\Shipment\OrderId;
use Logistics\Shipping\Domain\Shipment\Recipient;
use Logistics\Shipping\Domain\Shipment\Shipment;
use Logistics\Shipping\Domain\Shipment\ShipmentId;
use Logistics\Shipping\Domain\Shipment\ShipmentReference;
use Logistics\Shipping\Domain\Shipment\TrackingCode;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Builders\ShipmentBuilder;
use Tucano\SharedKernel\Identity\Snowflake\NodeId;
use Tucano\SharedKernel\Identity\Snowflake\Snowflake;

final class ZplLabelsTest extends TestCase
{
    #[Test]
    public function the_label_has_the_address_the_carrier_and_the_barcode_of_the_tracking_code(): void
    {
        $shipment = ShipmentBuilder::aShipment()->withParcels(
            ShipmentBuilder::parcel(2200, 240, 170, 80),
            ShipmentBuilder::parcel(350, 120, 90, 100),
        )->create()->toSnapshot();

        $label = new ZplLabels()->print($shipment);

        self::assertSame(['zpl', 'text/plain; charset=utf-8'], [$label->extension, $label->contentType]);
        self::assertStringStartsWith("^XA\n^CI28\n", $label->contents);
        self::assertStringEndsWith("^XZ\n", $label->contents);
        foreach ([
            '^FDtucano-express^FS',
            '^FDDe: CD GRU1^FS',
            '^FDAna Souza^FS',
            '^FDAvenida Paulista, 1000 - Apto 12^FS',
            '^FDSão Paulo - SP^FS',
            '^FDCEP 01310-100^FS',
            sprintf('^BCN,200,Y,N,N^FD%s^FS', $shipment->reference->trackingCode),
            '^FD2 volumes, 2,55 kg^FS',
        ] as $line) {
            self::assertStringContainsString($line, $label->contents);
        }
    }

    #[Test]
    public function what_the_customer_typed_cannot_become_printer_commands(): void
    {
        $shipment = Shipment::create(
            new ShipmentReference(ShipmentId::generate(), new TrackingCode(Snowflake::compose(1_790_510_400_000, new NodeId(1, 12), 0)), OrderId::generate()),
            CarrierCode::of('tucano-express'),
            FulfillmentCenterCode::of('GRU1'),
            Recipient::of('Ana^XZ~JA_Souza', 'ana@example.com'),
            ShipmentBuilder::destination(),
            Parcels::of(ShipmentBuilder::parcel(400, 100, 100, 120)),
            new DateTimeImmutable('2026-09-27T12:00:00Z'),
        )->toSnapshot();

        $contents = new ZplLabels()->print($shipment)->contents;

        self::assertStringContainsString('^FH_^FDAna_5EXZ_7EJA_5FSouza^FS', $contents);
        self::assertSame(1, substr_count($contents, '^XZ'), 'Only the real end of the label.');
    }
}
