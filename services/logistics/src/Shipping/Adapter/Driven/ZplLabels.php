<?php

declare(strict_types=1);

namespace Logistics\Shipping\Adapter\Driven;

use Logistics\Shipping\Application\LabelDocument;
use Logistics\Shipping\Application\Port\Driven\ForPrintingLabels;
use Logistics\Shipping\Domain\Shipment\ShipmentSnapshot;

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
        $to = $shipment->destination;
        $street = $to->complement === null ? sprintf('%s, %s', $to->street, $to->number) : sprintf('%s, %s - %s', $to->street, $to->number, $to->complement);
        $postalCode = (string) $to->postalCode;
        $parcels = count($shipment->parcels);

        $zpl = implode("\n", [
            '^XA',
            '^CI28',
            '^PW812',
            '^LL1218',
            self::text(40, 40, 50, (string) $shipment->carrier),
            self::text(40, 110, 30, sprintf('De: CD %s', $shipment->origin)),
            '^FO40,170^GB732,3,3^FS',
            self::text(40, 200, 40, $shipment->recipient->name),
            self::text(40, 255, 32, $street),
            self::text(40, 300, 32, $to->district),
            self::text(40, 345, 32, sprintf('%s - %s', $to->city, $to->state->value)),
            self::text(40, 390, 40, sprintf('CEP %s-%s', substr($postalCode, 0, 5), substr($postalCode, 5))),
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

    /** A line of text at x, y and the given height, with ^, ~ and _ written as hex so they print instead of acting. */
    private static function text(int $x, int $y, int $height, string $text): string
    {
        return sprintf('^FO%d,%d^A0N,%d,%d^FH_^FD%s^FS', $x, $y, $height, $height, strtr($text, ['_' => '_5F', '^' => '_5E', '~' => '_7E']));
    }
}
