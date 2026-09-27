<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tracking\Platform\Http\CorrelationId;

final class CorrelationIdTest extends TestCase
{
    private const string UUID_V7 = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    #[Test]
    public function it_keeps_the_id_that_came_from_the_gateway(): void
    {
        $correlationId = CorrelationId::fromHeader('4f1c2b7e-9d7a-4b8c-9e3f-1a2b3c4d5e6f#12');

        self::assertSame('4f1c2b7e-9d7a-4b8c-9e3f-1a2b3c4d5e6f#12', $correlationId->value);
    }

    #[Test]
    public function it_creates_a_uuid_v7_when_the_request_has_none(): void
    {
        self::assertMatchesRegularExpression(self::UUID_V7, CorrelationId::fromHeader(null)->value);
        self::assertMatchesRegularExpression(self::UUID_V7, CorrelationId::fromHeader('')->value);
    }

    #[Test]
    #[DataProvider('junk')]
    public function it_replaces_a_value_that_is_not_a_short_printable_token(string $header): void
    {
        self::assertMatchesRegularExpression(self::UUID_V7, CorrelationId::fromHeader($header)->value);
    }

    /** @return iterable<string, array{string}> */
    public static function junk(): iterable
    {
        yield 'too long' => [str_repeat('a', 129)];
        yield 'with spaces' => ['drop table orders'];
        yield 'with a line break' => ["abc\ninjected"];
        yield 'not ascii' => ['pedido-número-7'];
    }
}
