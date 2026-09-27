<?php

declare(strict_types=1);

namespace Tucano\SharedKernel\Tests\Address;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tucano\SharedKernel\Address\Address;
use Tucano\SharedKernel\Address\BrazilianState;
use Tucano\SharedKernel\Address\Coordinates;
use Tucano\SharedKernel\Address\Division;
use Tucano\SharedKernel\Address\Divisions;
use Tucano\SharedKernel\Address\InvalidAddress;
use Tucano\SharedKernel\Address\PostalCode;
use Tucano\SharedKernel\Address\Thoroughfare;

#[CoversClass(Address::class)]
#[CoversClass(Thoroughfare::class)]
#[CoversClass(PostalCode::class)]
#[CoversClass(Coordinates::class)]
final class AddressTest extends TestCase
{
    #[Test]
    public function a_number_on_a_highway_is_the_kilometre(): void
    {
        $address = new Address(
            new Thoroughfare('Rodovia', 'Fernão Dias'),
            'KM 500',
            'Galpão 3',
            Divisions::of(Division::state(BrazilianState::MG), Division::municipality('Betim', '3106705')),
            PostalCode::of('32669-000'),
        );

        self::assertSame('Rodovia Fernão Dias', (string) $address->thoroughfare);
        self::assertSame('KM 500', $address->number);
        self::assertSame(BrazilianState::MG, $address->state());
        self::assertSame('32669-000', $address->postalCode->formatted());
    }

    #[Test]
    public function what_is_typed_is_trimmed_and_a_blank_complement_is_none(): void
    {
        $address = new Address(new Thoroughfare(' Rua ', ' da Bahia '), ' S/N ', '   ', self::belo(), PostalCode::of('30160011'));

        self::assertSame(['Rua', 'da Bahia', 'S/N', null], [$address->thoroughfare->type, $address->thoroughfare->name, $address->number, $address->complement]);
    }

    #[Test]
    public function the_json_is_the_shape_of_the_events_and_the_api(): void
    {
        $address = new Address(new Thoroughfare('Avenida', 'Afonso Pena'), '1500', 'sala 3', self::belo(), PostalCode::of('30130-005'), new Coordinates(-19.9245, -43.9352));

        self::assertSame([
            'thoroughfare' => ['type' => 'Avenida', 'name' => 'Afonso Pena'],
            'number' => '1500',
            'complement' => 'sala 3',
            'divisions' => [
                ['kind' => 'state', 'code' => 'MG', 'name' => 'Minas Gerais'],
                ['kind' => 'municipality', 'code' => '3106200', 'name' => 'Belo Horizonte'],
                ['kind' => 'neighborhood', 'code' => null, 'name' => 'Centro'],
            ],
            'postalCode' => '30130005',
            'latitude' => -19.9245,
            'longitude' => -43.9352,
        ], $address->jsonSerialize());
    }

    /** @return iterable<string, array{Closure(): mixed}> */
    public static function invalidAddresses(): iterable
    {
        yield 'no number at all' => [static fn() => new Address(new Thoroughfare('Rua', 'da Bahia'), ' ', null, self::belo(), PostalCode::of('30160011'))];
        yield 'a number longer than the column' => [static fn() => new Address(new Thoroughfare('Rua', 'da Bahia'), str_repeat('1', 21), null, self::belo(), PostalCode::of('30160011'))];
        yield 'a complement longer than the column' => [static fn() => new Address(new Thoroughfare('Rua', 'da Bahia'), '1200', str_repeat('b', 81), self::belo(), PostalCode::of('30160011'))];
        yield 'a thoroughfare without a type' => [static fn() => new Thoroughfare('', 'da Bahia')];
        yield 'a type longer than the column' => [static fn() => new Thoroughfare(str_repeat('R', 31), 'da Bahia')];
        yield 'a thoroughfare without a name' => [static fn() => new Thoroughfare('Rua', '  ')];
        yield 'a name longer than the column' => [static fn() => new Thoroughfare('Rua', str_repeat('a', 161))];
        yield 'a short CEP' => [static fn() => PostalCode::of('3016001')];
        yield 'coordinates off the globe' => [static fn() => new Coordinates(-91.0, 0.0)];
    }

    /** @param Closure(): mixed $build */
    #[Test]
    #[DataProvider('invalidAddresses')]
    public function an_address_nobody_could_deliver_to_is_refused(Closure $build): void
    {
        $this->expectException(InvalidAddress::class);

        $build();
    }

    private static function belo(): Divisions
    {
        return Divisions::of(Division::state(BrazilianState::MG), Division::municipality('Belo Horizonte', '3106200'), Division::neighborhood('Centro'));
    }
}
