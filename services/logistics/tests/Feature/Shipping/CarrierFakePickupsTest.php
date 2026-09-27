<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Logistics\Shipping\Adapter\Driven\CarrierFakePickups;
use Logistics\Shipping\Application\PickupOrder;
use Logistics\Shipping\Domain\Destination\BrazilianState;
use Logistics\Shipping\Domain\Error\PickupNotBooked;
use Logistics\Shipping\Domain\Error\PickupRefused;
use Logistics\Shipping\Domain\Shipment\ShipmentStatus;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\RequestInterface;
use Tests\Builders\ShipmentBuilder;
use Tests\TestCase;
use Throwable;

final class CarrierFakePickupsTest extends TestCase
{
    /** @var list<RequestInterface> */
    private array $sent = [];

    #[Test]
    public function the_shipment_id_travels_as_the_key_and_the_reference_and_no_name_goes_out(): void
    {
        $order = self::order();
        $snapshot = $order->shipment;

        $this->carriers(new Response(201, [], '{"id":"pk_01ABC","status":"scheduled"}'))->schedule($order);

        $request = $this->sent[0];
        self::assertSame(['POST', '/carriers/v1/pickups', $snapshot->reference->id->toString()], [$request->getMethod(), $request->getUri()->getPath(), $request->getHeaderLine('Idempotency-Key')]);
        self::assertSame([
            'carrier' => 'tucano-express',
            'reference' => $snapshot->reference->id->toString(),
            'trackingCode' => (string) $snapshot->reference->trackingCode,
            'origin' => ['center' => 'GRU1', 'state' => 'SP'],
            'destination' => ['city' => 'São Paulo', 'state' => 'SP', 'postalCode' => '01310100'],
            'parcels' => 1,
            'weightGrams' => $snapshot->parcels->totalWeight()->grams(),
        ], json_decode((string) $request->getBody(), true));
    }

    #[Test]
    public function a_carrier_that_fails_or_does_not_answer_did_not_book(): void
    {
        foreach ([new Response(503), new ConnectException('Connection refused', new Request('POST', '/'))] as $answer) {
            try {
                $this->carriers($answer)->schedule(self::order());
                self::fail('A failure was expected.');
            } catch (PickupNotBooked) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function a_refusal_keeps_the_reason_of_the_carrier(): void
    {
        $this->expectExceptionObject(PickupRefused::because('Idempotency-Key k-1 was used with another body.'));

        $this->carriers(new Response(422, [], '{"detail":"Idempotency-Key k-1 was used with another body."}'))->schedule(self::order());
    }

    private function carriers(Response|Throwable $answer): CarrierFakePickups
    {
        $stack = HandlerStack::create(new MockHandler([$answer]));
        $stack->push(Middleware::mapRequest(function (RequestInterface $request): RequestInterface {
            $this->sent[] = $request;

            return $request;
        }));

        return new CarrierFakePickups(new Client(['handler' => $stack, 'base_uri' => 'http://carriers.test']), 2_000);
    }

    private static function order(): PickupOrder
    {
        return new PickupOrder(ShipmentBuilder::aShipment()->in(ShipmentStatus::ReadyForPickup)->toSnapshot(), BrazilianState::SP);
    }
}
