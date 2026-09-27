<?php

declare(strict_types=1);

namespace Tucano\SharedKernel\Tests\Identity;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tucano\SharedKernel\Identity\CrockfordBase32;

#[CoversClass(CrockfordBase32::class)]
final class CrockfordBase32Test extends TestCase
{
    #[Test]
    public function it_pads_codes_to_thirteen_symbols(): void
    {
        self::assertSame('0000000000000', CrockfordBase32::encode(0));
        self::assertSame('000000000000Z', CrockfordBase32::encode(31));
        self::assertSame('02PQRFBTW5G03', CrockfordBase32::encode(97663548934766595));
    }

    #[Test]
    public function it_encodes_the_largest_positive_integer(): void
    {
        self::assertSame(PHP_INT_MAX, CrockfordBase32::decode(CrockfordBase32::encode(PHP_INT_MAX)));
        self::assertSame('7ZZZZZZZZZZZZ', CrockfordBase32::encode(PHP_INT_MAX));
    }

    #[Test]
    public function it_reads_codes_typed_by_people(): void
    {
        self::assertSame(97663548934766595, CrockfordBase32::decode('02pqrfbtw5g03'));
        self::assertSame(CrockfordBase32::decode('0110'), CrockfordBase32::decode('OIL0'));
        self::assertSame(CrockfordBase32::decode('02PQ'), CrockfordBase32::decode('0-2PQ'));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidCodes(): iterable
    {
        yield 'empty' => [''];
        yield 'U is not part of the alphabet' => ['0U1'];
        yield 'symbol outside the alphabet' => ['12#4'];
        yield 'too long' => ['00000000000000'];
        yield 'beyond 63 bits' => ['8000000000000'];
    }

    #[Test]
    #[DataProvider('invalidCodes')]
    public function it_rejects_invalid_codes(string $code): void
    {
        $this->expectException(InvalidArgumentException::class);

        CrockfordBase32::decode($code);
    }
}
