<?php

declare(strict_types=1);

namespace Tucano\SharedKernel\Tests\Address;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tucano\SharedKernel\Address\Address;
use Tucano\SharedKernel\Address\AddressBuilder;
use Tucano\SharedKernel\Address\BrazilianState;
use Tucano\SharedKernel\Address\Coordinates;
use Tucano\SharedKernel\Address\Division;
use Tucano\SharedKernel\Address\Divisions;
use Tucano\SharedKernel\Address\InvalidAddress;
use Tucano\SharedKernel\Address\PostalCode;
use Tucano\SharedKernel\Address\Thoroughfare;

#[CoversClass(Address::class)]
#[CoversClass(AddressBuilder::class)]
#[CoversClass(Thoroughfare::class)]
#[CoversClass(PostalCode::class)]
#[CoversClass(Coordinates::class)]
final class AddressTest extends TestCase
{
    #[Test]
    public function the_builder_takes_the_parts_in_the_order_people_say_them(): void
    {
        $address = self::bahia()->complement('apto 42')->coordinates(-19.9245, -43.9352)->build();

        self::assertSame('Rua da Bahia, 1200 - apto 42', $address->thoroughfareLine());
        self::assertSame(BrazilianState::MG, $address->state());
        self::assertSame('Belo Horizonte', $address->municipality()->name);
        self::assertSame('30160-011', $address->postalCode->formatted());
        self::assertEquals(Coordinates::of(-19.9245, -43.9352), $address->coordinates);
    }

    #[Test]
    public function on_a_highway_the_number_is_the_kilometre(): void
    {
        $address = Address::builder()
            ->thoroughfare('Rodovia', 'Fernão Dias')->number('KM 500')->complement('Galpão 3')
            ->state(BrazilianState::MG)->municipality('Betim', '3106705')
            ->postalCode('32669-000')
            ->build();

        self::assertSame('Rodovia Fernão Dias, KM 500 - Galpão 3', $address->thoroughfareLine());
    }

    #[Test]
    public function what_is_typed_is_trimmed_and_a_blank_complement_is_none(): void
    {
        $address = self::bahia()->thoroughfare(' Rua ', ' da Bahia ')->number(' S/N ')->complement('   ')->build();

        self::assertSame(['Rua', 'da Bahia', 'S/N', null], [$address->thoroughfare->type, $address->thoroughfare->name, $address->number, $address->complement]);
    }

    #[Test]
    public function to_array_is_the_shape_of_the_api_and_the_events_and_from_array_reads_it_back(): void
    {
        $address = self::bahia()->complement('sala 3')->coordinates(-19.9245, -43.9352)->build();

        self::assertSame([
            'thoroughfare' => ['type' => 'Rua', 'name' => 'da Bahia'],
            'number' => '1200',
            'complement' => 'sala 3',
            'divisions' => [
                ['kind' => 'state', 'code' => 'MG', 'name' => 'Minas Gerais'],
                ['kind' => 'municipality', 'code' => '3106200', 'name' => 'Belo Horizonte'],
                ['kind' => 'neighborhood', 'code' => null, 'name' => 'Centro'],
            ],
            'postalCode' => '30160011',
            'latitude' => -19.9245,
            'longitude' => -43.9352,
        ], $address->toArray());
        self::assertEquals($address, Address::fromArray($address->toArray()));
        $decoded = json_decode(json_encode($address, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertEquals($address, Address::fromArray($decoded));
    }

    #[Test]
    public function of_takes_the_parts_already_built(): void
    {
        $address = Address::of(
            Thoroughfare::of('Avenida', 'Afonso Pena'),
            '1500',
            null,
            Divisions::of(Division::state(BrazilianState::MG), Division::municipality('Belo Horizonte')),
            PostalCode::of('30130-005'),
        );

        self::assertSame('Avenida Afonso Pena, 1500', $address->thoroughfareLine());
        self::assertNull($address->coordinates);
    }

    /** @return iterable<string, array{Closure(): mixed}> */
    public static function invalidAddresses(): iterable
    {
        yield 'no number at all' => [static fn() => self::bahia()->number(' ')->build()];
        yield 'a number longer than the column' => [static fn() => self::bahia()->number(str_repeat('1', 21))->build()];
        yield 'a complement longer than the column' => [static fn() => self::bahia()->complement(str_repeat('b', 81))->build()];
        yield 'nothing but the territory' => [static fn() => Address::builder()->state(BrazilianState::MG)->municipality('Belo Horizonte')->build()];
        yield 'no territory' => [static fn() => Address::builder()->thoroughfare('Rua', 'da Bahia')->number('1200')->postalCode('30160011')->build()];
        yield 'half of the coordinates' => [static fn() => self::bahia()->coordinates(-19.9, null)];
        yield 'a thoroughfare without a type' => [static fn() => Thoroughfare::of('', 'da Bahia')];
        yield 'a type longer than the column' => [static fn() => Thoroughfare::of(str_repeat('R', 31), 'da Bahia')];
        yield 'a thoroughfare without a name' => [static fn() => Thoroughfare::of('Rua', '  ')];
        yield 'a name longer than the column' => [static fn() => Thoroughfare::of('Rua', str_repeat('a', 161))];
        yield 'a short CEP' => [static fn() => PostalCode::of('3016001')];
        yield 'coordinates off the globe' => [static fn() => Coordinates::of(-91.0, 0.0)];
        yield 'an array without the thoroughfare' => [static fn() => Address::fromArray(['number' => '1200', 'divisions' => [], 'postalCode' => '30160011'])];
        yield 'an array with the number as a number' => [static fn() => Address::fromArray([...self::bahia()->build()->toArray(), 'number' => 1200])];
        yield 'an array with a latitude in words' => [static fn() => Address::fromArray([...self::bahia()->build()->toArray(), 'latitude' => 'south'])];
    }

    /** @param Closure(): mixed $build */
    #[Test]
    #[DataProvider('invalidAddresses')]
    public function an_address_nobody_could_deliver_to_is_refused(Closure $build): void
    {
        $this->expectException(InvalidAddress::class);

        $build();
    }

    private static function bahia(): AddressBuilder
    {
        return Address::builder()
            ->thoroughfare('Rua', 'da Bahia')->number('1200')
            ->state(BrazilianState::MG)->municipality('Belo Horizonte', '3106200')->neighborhood('Centro')
            ->postalCode('30160-011');
    }
}
