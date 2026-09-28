<?php

declare(strict_types=1);

namespace Logistics\Shipping\Adapter\Driven;

use Logistics\Shipping\Application\LabelDocument;
use Logistics\Shipping\Application\Port\Driven\ForPrintingLabels;
use Logistics\Shipping\Domain\Shipment\ShipmentSnapshot;
use Tucano\SharedKernel\Address\Address;
use Tucano\SharedKernel\Address\Division;
use Tucano\SharedKernel\Address\DivisionKind;

/**
 * ZPL, the language of the thermal printers in the warehouses: the label is
 * text and the printer draws the barcode. A 4 x 6 inch label at 203 dpi, with
 * ^CI28 so names and cities keep their accents.
 *
 * What the customer typed goes through ^FH: a name with ^ or ~ in it would
 * otherwise be read as printer commands (ZPL injection).
 */
final readonly class ZplLabels implements ForPrintingLabels
{
    public function print(ShipmentSnapshot $shipment): LabelDocument
    {
        $parcels = count($shipment->parcels);

        $zpl = implode("\n", [
            '^XA',
            '^CI28',
            '^PW812',
            '^LL1218',
            self::text(40, 40, 50, (string) $shipment->carrier),
            self::text(40, 110, 30, sprintf('De: CD %s', $shipment->origin)),
            '^FO40,170^GB732,3,3^FS',
            // The one place the name is printed on purpose: the label takes it to the door.
            self::text(40, 200, 40, $shipment->recipient->name->reveal()),
            ...self::addressBlock($shipment->destination),
            '^FO40,470^GB732,3,3^FS',
            sprintf('^FO80,520^BY3^BCN,200,Y,N,N^FD%s^FS', $shipment->reference->trackingCode),
            self::text(40, 820, 30, sprintf(
                '%d %s, %s kg',
                $parcels,
                $parcels === 1 ? 'volume' : 'volumes',
                number_format($shipment->parcels->totalWeight()->grams() / 1_000, 2, ',', '.'),
            )),
            '^XZ',
        ]);

        return new LabelDocument($zpl . "\n", 'zpl', 'text/plain; charset=utf-8');
    }

    /**
     * The thoroughfare line, the divisions inside the municipality (narrowest
     * first, when there are any), the municipality with the UF, and the CEP.
     *
     * @return list<string>
     */
    private static function addressBlock(Address $to): array
    {
        $local = implode(', ', array_map(static fn(Division $division): string => $division->name, array_reverse($to->divisions->below(DivisionKind::Municipality))));
        $lines = array_values(array_filter([
            [$to->thoroughfareLine(), 32],
            [$local, 32],
            [sprintf('%s - %s', $to->municipality()->name, $to->state()->value), 32],
            ['CEP ' . $to->postalCode->formatted(), 40],
        ], static fn(array $line): bool => $line[0] !== ''));

        return array_map(static fn(array $line, int $index): string => self::text(40, 255 + 45 * $index, $line[1], $line[0]), $lines, array_keys($lines));
    }

    /** A line of text at x, y and the given height, with ^, ~ and _ written as hex so they print instead of acting. */
    private static function text(int $x, int $y, int $height, string $text): string
    {
        return sprintf('^FO%d,%d^A0N,%d,%d^FH_^FD%s^FS', $x, $y, $height, $height, strtr($text, ['_' => '_5F', '^' => '_5E', '~' => '_7E']));
    }
}
