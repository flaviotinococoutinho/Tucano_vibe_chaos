<?php

declare(strict_types=1);

namespace Tests\Unit\Shipping;

use Closure;
use Logistics\CarrierSelection\Application\CarrierRequest;
use Logistics\CarrierSelection\Application\ChosenCarrier;
use Logistics\CarrierSelection\Application\Port\Driving\ForChoosingCarriers;
use Logistics\CarrierSelection\Domain\Consignment;
use Logistics\CarrierSelection\Domain\NoCarrierFits;
use Logistics\CarrierSelection\Domain\UnknownFulfillmentCenter;
use Logistics\Shipping\Adapter\Driven\CarrierSelectionChoices;
use Logistics\Shipping\Domain\Error\NoCarrierChosen;
use Logistics\Shipping\Domain\Parcel\Parcels;
use Logistics\Shipping\Domain\Shipment\FulfillmentCenterCode;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Builders\ShipmentBuilder;
use Tucano\SharedKernel\Domain\ErrorCategory;

final class CarrierSelectionChoicesTest extends TestCase
{
    #[Test]
    public function the_request_goes_in_plain_values_and_the_carrier_comes_back(): void
    {
        $asked = [];
        $selection = self::selection(static function (CarrierRequest $request) use (&$asked): ChosenCarrier {
            $asked[] = [$request->originCenter, $request->destinationState, $request->weightGrams];

            return new ChosenCarrier('tucano-express');
        });

        $carrier = new CarrierSelectionChoices($selection)->choose(FulfillmentCenterCode::of('GRU1'), ShipmentBuilder::destination(), self::parcels());

        self::assertSame('tucano-express', (string) $carrier);
        self::assertSame([['GRU1', 'SP', 2200]], $asked);
    }

    #[Test]
    public function a_refusal_of_carrier_selection_becomes_a_shipping_error_of_the_same_category(): void
    {
        $refusals = [
            NoCarrierFits::for(new Consignment('SP', 'AM', 2200)),
            UnknownFulfillmentCenter::withCode('XXX9'),
        ];

        foreach ($refusals as $refusal) {
            $choices = new CarrierSelectionChoices(self::selection(static fn(): ChosenCarrier => throw $refusal));
            try {
                $choices->choose(FulfillmentCenterCode::of('GRU1'), ShipmentBuilder::destination(), self::parcels());
                self::fail('The refusal was expected.');
            } catch (NoCarrierChosen $translated) {
                self::assertSame([$refusal->category(), $refusal->getMessage(), $refusal], [$translated->category(), $translated->getMessage(), $translated->getPrevious()]);
            }
        }
        self::assertSame(ErrorCategory::Conflict, $refusals[0]->category());
    }

    /** @param Closure(CarrierRequest): ChosenCarrier $answer */
    private static function selection(Closure $answer): ForChoosingCarriers
    {
        return new readonly class ($answer) implements ForChoosingCarriers {
            /** @param Closure(CarrierRequest): ChosenCarrier $answer */
            public function __construct(private Closure $answer) {}

            public function choose(CarrierRequest $request): ChosenCarrier
            {
                return ($this->answer)($request);
            }
        };
    }

    private static function parcels(): Parcels
    {
        return Parcels::of(ShipmentBuilder::parcel(2200, 240, 170, 80));
    }
}
