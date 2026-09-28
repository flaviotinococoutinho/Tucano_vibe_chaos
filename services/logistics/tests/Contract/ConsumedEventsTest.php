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
        yield 'order paid on commerce.orders.v1, with the address from before ADR 0020' => [OrderEvents::paid(['shippingAddress' => OrderEvents::legacyAddress()]), 'commerce.order.paid.v1.schema.json'];
        yield 'order cancelled after payment' => [OrderEvents::cancelled(), 'commerce.order.cancelled.schema.json'];
        yield 'order cancelled before payment' => [OrderEvents::expired(), 'commerce.order.cancelled.schema.json'];
        yield 'product snapshot' => [CatalogEvents::snapshot(), 'catalog.product.snapshot.schema.json'];
        yield 'discontinued product snapshot' => [CatalogEvents::snapshot(['status' => 'discontinued', 'version' => 4]), 'catalog.product.snapshot.schema.json'];
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
