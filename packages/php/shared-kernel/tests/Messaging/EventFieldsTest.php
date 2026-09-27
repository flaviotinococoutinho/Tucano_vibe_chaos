<?php

declare(strict_types=1);

namespace Tucano\SharedKernel\Tests\Messaging;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tucano\SharedKernel\Messaging\EventFields;

#[CoversClass(EventFields::class)]
final class EventFieldsTest extends TestCase
{
    private const array DATA = [
        'orderId' => '01999a1e-3c4d-7a2b-8c9d-0e1f2a3b4c5d',
        'total' => ['amount' => 18990, 'currency' => 'BRL'],
        'items' => [['sku' => 'BOOK-DDD-001', 'quantity' => 2]],
        'coupon' => null,
        'somethingNew' => 'ignored',
    ];

    #[Test]
    public function it_reads_what_the_consumer_asks_for_and_ignores_the_rest(): void
    {
        $fields = new EventFields(self::DATA);

        self::assertSame('01999a1e-3c4d-7a2b-8c9d-0e1f2a3b4c5d', $fields->uuid('orderId'));
        self::assertSame([18990, 'BRL'], [$fields->object('total')->integer('amount'), $fields->object('total')->text('currency')]);
        self::assertSame('BOOK-DDD-001', $fields->objects('items')[0]->text('sku'));
        self::assertNull($fields->optionalText('coupon'));
        self::assertNull($fields->optionalNumber('latitude'));
    }

    #[Test]
    public function a_missing_or_mistyped_field_says_where_it_is(): void
    {
        $fields = new EventFields(self::DATA);

        foreach ([
            'The event has no integer total.currency.' => static fn() => $fields->object('total')->integer('currency'),
            'The event has no text items[0].name.' => static fn() => $fields->objects('items')[0]->text('name'),
            'The event has no UUID items[0].sku.' => static fn() => $fields->objects('items')[0]->uuid('sku'),
            'The event has no list total.' => static fn() => $fields->objects('total'),
        ] as $message => $read) {
            try {
                $read();
                self::fail(sprintf('Expected "%s".', $message));
            } catch (InvalidArgumentException $missing) {
                self::assertSame($message, $missing->getMessage());
            }
        }
    }
}
