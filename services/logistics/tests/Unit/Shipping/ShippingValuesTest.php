<?php

declare(strict_types=1);

namespace Tests\Unit\Shipping;

use Closure;
use Logistics\Shipping\Domain\Error\InvalidShipment;
use Logistics\Shipping\Domain\Parcel\Dimensions;
use Logistics\Shipping\Domain\Parcel\Parcels;
use Logistics\Shipping\Domain\Parcel\Quantity;
use Logistics\Shipping\Domain\Parcel\Weight;
use Logistics\Shipping\Domain\Product\CatalogProduct;
use Logistics\Shipping\Domain\Product\Sku;
use Logistics\Shipping\Domain\Shipment\CarrierCode;
use Logistics\Shipping\Domain\Shipment\DeliveryAttempts;
use Logistics\Shipping\Domain\Shipment\FulfillmentCenterCode;
use Logistics\Shipping\Domain\Shipment\Recipient;
use Logistics\Shipping\Domain\Shipment\StoreSlug;
use Logistics\Shipping\Domain\Shipment\TrackingCode;
use Logistics\Shipping\Domain\Transition\DeliveryFailure;
use Logistics\Shipping\Domain\Transition\Hub;
use Logistics\Shipping\Domain\Transition\ProofOfDelivery;
use Logistics\Shipping\Domain\Transition\ShippingLabel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Builders\ShipmentBuilder;
use Tucano\SharedKernel\Identity\Snowflake\NodeId;
use Tucano\SharedKernel\Identity\Snowflake\Snowflake;

final class ShippingValuesTest extends TestCase
{
    #[Test]
    public function the_tracking_code_is_the_snowflake_in_crockford_base32(): void
    {
        // The example of docs/architecture/identifiers.md.
        $code = TrackingCode::fromSnowflake(Snowflake::fromInt(97663548934766595));

        self::assertSame('TX02PQRFBTW5G03', (string) $code);
        self::assertSame(15, strlen((string) $code));
        self::assertEquals(new NodeId(1, 12), $code->snowflake->node());
        self::assertSame('2026-09-27T12:00:04.567Z', $code->snowflake->createdAt()->format('Y-m-d\TH:i:s.v\Z'));
    }

    #[Test]
    public function a_parcel_holds_every_unit_of_a_line_stacked(): void
    {
        $product = new CatalogProduct(Sku::of('BOOK-DDD-001'), StoreSlug::of('arara'), Weight::ofGrams(1100), Dimensions::ofMillimetres(240, 170, 40));

        $parcel = $product->packed(Quantity::of(3));

        self::assertSame(3300, $parcel->weight->grams());
        self::assertEquals(Dimensions::ofMillimetres(240, 170, 120), $parcel->dimensions);
    }

    #[Test]
    public function the_total_weight_is_the_sum_of_the_parcels(): void
    {
        $parcels = Parcels::of(ShipmentBuilder::parcel(2200, 240, 170, 80), ShipmentBuilder::parcel(4990, 300, 300, 300), ShipmentBuilder::parcel(10, 10, 10, 10));

        self::assertSame(7200, $parcels->totalWeight()->grams());
        self::assertCount(3, $parcels);
    }

    #[Test]
    public function the_last_failure_decides_whether_a_return_is_allowed_before_the_third_attempt(): void
    {
        $absent = DeliveryAttempts::none()->failed(DeliveryFailure::RecipientAbsent);
        $refused = DeliveryAttempts::none()->failed(DeliveryFailure::RecipientRefused);

        self::assertSame([1, 2, true, false], [$absent->made, $absent->next(), $absent->allowAnother(), $absent->allowReturn()]);
        self::assertTrue($refused->allowReturn());
        self::assertTrue($refused->allowAnother());
        self::assertFalse(DeliveryAttempts::none()->allowReturn());
    }

    #[Test]
    public function values_are_normalized(): void
    {
        self::assertSame('BOOK-DDD-001', (string) Sku::of(' book-ddd-001 '));
        self::assertSame('Hub Cajamar', Hub::named('  Hub Cajamar ')->name);
        $recipient = Recipient::of(' Ana Souza ', ' ana@example.com');
        self::assertSame(['Ana Souza', 'ana@example.com'], [$recipient->name->reveal(), $recipient->email->reveal()]);
    }

    #[Test]
    public function the_store_of_many_facts_is_the_one_they_all_name(): void
    {
        $sabia = StoreSlug::of('sabia');

        self::assertEquals($sabia, StoreSlug::sharedBy($sabia, StoreSlug::of('sabia')));
        self::assertNull(StoreSlug::sharedBy($sabia, StoreSlug::of('arara')), 'Two stores: no answer is better than the wrong one.');
        self::assertNull(StoreSlug::sharedBy($sabia, null), 'One of them does not know its store yet.');
        self::assertNull(StoreSlug::sharedBy(null, null));
        self::assertNull(StoreSlug::sharedBy());
        self::assertTrue($sabia->equals(StoreSlug::of('sabia')));
        self::assertFalse($sabia->equals(StoreSlug::of('arara')));
    }

    #[Test]
    public function who_receives_shows_only_a_mask_when_printed_by_accident(): void
    {
        $recipient = Recipient::of('Ana Souza', 'ana@example.com');
        $proof = ProofOfDelivery::of('Carlos Lima', '123.456.789-09');

        self::assertSame(
            'A*** S*** <a***@example.com>, received by C*** L*** (***09)',
            sprintf('%s <%s>, received by %s (%s)', $recipient->name, $recipient->email, $proof->receiverName, $proof->receiverDocument),
        );
    }

    /** @return iterable<string, array{Closure(): mixed}> */
    public static function invalidValues(): iterable
    {
        yield 'no weight' => [static fn() => Weight::ofGrams(0)];
        yield 'a weight the INTEGER column cannot hold' => [static fn() => Weight::ofGrams(2_147_483_648)];
        yield 'a weight that overflows once multiplied' => [static fn() => Weight::ofGrams(1_000_000_000)->times(Quantity::of(3))];
        yield 'a flat box' => [static fn() => Dimensions::ofMillimetres(240, 170, 0)];
        yield 'a stack taller than the column' => [static fn() => Dimensions::ofMillimetres(240, 170, 2_000_000_000)->stacked(Quantity::of(2))];
        yield 'no units' => [static fn() => Quantity::of(0)];
        yield 'no parcels' => [static fn() => Parcels::of()];
        yield 'a carrier in capitals' => [static fn() => CarrierCode::of('Tucano-Express')];
        yield 'a warehouse in lowercase' => [static fn() => FulfillmentCenterCode::of('gru1')];
        yield 'a SKU with spaces' => [static fn() => Sku::of('BOOK DDD')];
        yield 'a store in capitals' => [static fn() => StoreSlug::of('Sabia')];
        yield 'a store of one letter' => [static fn() => StoreSlug::of('s')];
        yield 'a store that starts with a digit' => [static fn() => StoreSlug::of('9sabia')];
        yield 'a store longer than the column' => [static fn() => StoreSlug::of('s' . str_repeat('a', 31))];
        yield 'a store with spaces' => [static fn() => StoreSlug::of(' sabia')];
        yield 'a recipient without a name' => [static fn() => Recipient::of(' ', 'ana@example.com')];
        yield 'a recipient without an e-mail' => [static fn() => Recipient::of('Ana Souza', '')];
        yield 'a label without a key' => [static fn() => ShippingLabel::storedAt('')];
        yield 'a hub without a name' => [static fn() => Hub::named('')];
        yield 'a proof without a document' => [static fn() => ProofOfDelivery::of('Ana Souza', ' ')];
        yield 'four attempts' => [static fn() => DeliveryAttempts::restore(4, null)];
    }

    /** @param Closure(): mixed $build */
    #[Test]
    #[DataProvider('invalidValues')]
    public function invalid_values_are_refused(Closure $build): void
    {
        $this->expectException(InvalidShipment::class);

        $build();
    }
}
