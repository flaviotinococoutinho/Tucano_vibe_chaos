<?php

declare(strict_types=1);

namespace Tests\Contract;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Tests\AssertsContracts;
use Tests\Fixtures\CatalogEvents;
use Tests\Fixtures\OrderEvents;

/**
 * The handlers are tested with these fixtures, so they must be exactly what
 * Commerce and the catalog publish. When a producer changes its contract,
 * this test is the first to say so.
 */
final class ConsumedEventsTest extends TestCase
{
    use AssertsContracts;

    /** @return iterable<string, array{string, string}> */
    public static function fixtures(): iterable
    {
        yield 'order paid' => [OrderEvents::paid(), 'commerce.order.paid.schema.json'];
        yield 'order paid, to an address without complement or coordinates' => [OrderEvents::paid(['shippingAddress' => OrderEvents::plainAddress(), 'fulfillmentCenter' => 'BHZ1']), 'commerce.order.paid.schema.json'];
        yield 'order paid before the stores' => [OrderEvents::paidBeforeTheStores(), 'commerce.order.paid.schema.json'];
        yield 'order cancelled after payment' => [OrderEvents::cancelled(), 'commerce.order.cancelled.schema.json'];
        yield 'order cancelled before payment' => [OrderEvents::expired(), 'commerce.order.cancelled.schema.json'];
        yield 'product snapshot' => [CatalogEvents::snapshot(), 'catalog.product.snapshot.schema.json'];
        yield 'discontinued product snapshot' => [CatalogEvents::snapshot(['status' => 'discontinued', 'version' => 4]), 'catalog.product.snapshot.schema.json'];
        yield 'product snapshot from before the stores' => [CatalogEvents::snapshotBeforeTheStores(), 'catalog.product.snapshot.schema.json'];
    }

    #[Test]
    public function the_fixtures_say_the_store_the_way_the_producers_do(): void
    {
        $paid = json_decode(OrderEvents::paid(), flags: JSON_THROW_ON_ERROR);
        $snapshot = json_decode(CatalogEvents::snapshot(), flags: JSON_THROW_ON_ERROR);
        $paidBefore = json_decode(OrderEvents::paidBeforeTheStores(), flags: JSON_THROW_ON_ERROR);
        $snapshotBefore = json_decode(CatalogEvents::snapshotBeforeTheStores(), flags: JSON_THROW_ON_ERROR);
        self::assertInstanceOf(stdClass::class, $paid);
        self::assertInstanceOf(stdClass::class, $snapshot);
        self::assertInstanceOf(stdClass::class, $paidBefore);
        self::assertInstanceOf(stdClass::class, $snapshotBefore);

        self::assertSame([OrderEvents::STORE, CatalogEvents::STORE], [$paid->data->store ?? null, $snapshot->data->store ?? null]);
        self::assertObjectNotHasProperty('store', $paidBefore->data, 'A fact from before the stores has no store, and no null either.');
        self::assertObjectNotHasProperty('store', $snapshotBefore->data);
    }

    #[Test]
    #[DataProvider('fixtures')]
    public function the_fixtures_speak_the_published_language_of_the_producers(string $payload, string $schema): void
    {
        $event = json_decode($payload, flags: JSON_THROW_ON_ERROR);
        self::assertInstanceOf(stdClass::class, $event);

        self::assertMatchesContract('cloudevent.schema.json', $event);
        self::assertMatchesContract($schema, $event->data);
    }
}
