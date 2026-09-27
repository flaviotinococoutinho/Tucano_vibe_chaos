<?php

declare(strict_types=1);

namespace Tucano\SharedKernel\Tests\Address;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tucano\SharedKernel\Address\BrazilianState;
use Tucano\SharedKernel\Address\Division;
use Tucano\SharedKernel\Address\DivisionKind;
use Tucano\SharedKernel\Address\Divisions;
use Tucano\SharedKernel\Address\InvalidAddress;

#[CoversClass(Divisions::class)]
#[CoversClass(Division::class)]
#[CoversClass(DivisionKind::class)]
#[CoversClass(BrazilianState::class)]
final class DivisionsTest extends TestCase
{
    #[Test]
    public function the_territory_goes_from_the_state_down_to_the_neighborhood(): void
    {
        $divisions = Divisions::of(
            Division::state(BrazilianState::MG),
            Division::municipality('Belo Horizonte', '3106200'),
            Division::district('Belo Horizonte', '310620005'),
            Division::neighborhood('  Centro '),
        );

        self::assertSame(BrazilianState::MG, $divisions->state());
        self::assertSame('Belo Horizonte', $divisions->municipality()->name);
        self::assertSame('Centro', $divisions->narrowest()->name);
        self::assertSame(
            ['state', 'municipality', 'district', 'neighborhood'],
            array_map(static fn(Division $division): string => $division->kind->value, iterator_to_array($divisions, false)),
        );
    }

    #[Test]
    public function the_levels_below_the_municipality_are_optional(): void
    {
        $divisions = Divisions::of(Division::state(BrazilianState::SP), Division::municipality('Cajamar'));

        self::assertSame('Cajamar', $divisions->narrowest()->name);
        self::assertNull($divisions->municipality()->code);
    }

    #[Test]
    public function a_state_is_known_by_its_uf_and_named_as_ibge_names_it(): void
    {
        self::assertEquals(new Division(DivisionKind::State, 'São Paulo', 'SP'), Division::state(BrazilianState::SP));
        self::assertCount(27, array_unique(array_map(static fn(BrazilianState $state): string => $state->ibgeCode(), BrazilianState::cases())));
        self::assertCount(27, array_unique(array_map(static fn(BrazilianState $state): string => $state->officialName(), BrazilianState::cases())));
        self::assertSame(['31', '53'], [BrazilianState::MG->ibgeCode(), BrazilianState::DF->ibgeCode()]);
    }

    #[Test]
    public function up_to_a_kind_keeps_the_broader_levels(): void
    {
        $divisions = Divisions::of(Division::state(BrazilianState::MG), Division::municipality('Contagem', '3118601'), Division::neighborhood('Eldorado'));

        self::assertSame(
            [['kind' => 'state', 'code' => 'MG', 'name' => 'Minas Gerais'], ['kind' => 'municipality', 'code' => '3118601', 'name' => 'Contagem']],
            $divisions->upTo(DivisionKind::Municipality)->jsonSerialize(),
        );
    }

    #[Test]
    public function the_json_comes_back_as_the_same_divisions(): void
    {
        $divisions = Divisions::of(
            Division::state(BrazilianState::RJ),
            Division::municipality('Rio de Janeiro', '3304557'),
            Division::neighborhood('Copacabana'),
        );

        self::assertEquals($divisions, Divisions::fromJson(json_encode($divisions, JSON_THROW_ON_ERROR)));
    }

    /** @return iterable<string, array{Closure(): mixed}> */
    public static function invalidTerritories(): iterable
    {
        yield 'no municipality' => [static fn() => Divisions::of(Division::state(BrazilianState::SP), Division::neighborhood('Pinheiros'))];
        yield 'no state' => [static fn() => Divisions::of(Division::municipality('São Paulo'), Division::neighborhood('Pinheiros'))];
        yield 'a neighborhood above a district' => [static fn() => Divisions::of(Division::state(BrazilianState::SP), Division::municipality('Campinas'), Division::neighborhood('Cidade Universitária'), Division::district('Barão Geraldo'))];
        yield 'the same kind twice' => [static fn() => Divisions::of(Division::state(BrazilianState::SP), Division::municipality('Campinas'), Division::neighborhood('Cambuí'), Division::neighborhood('Centro'))];
        yield 'a municipality of São Paulo in Minas Gerais' => [static fn() => Divisions::of(Division::state(BrazilianState::MG), Division::municipality('São Paulo', '3550308'))];
        yield 'a district outside its municipality' => [static fn() => Divisions::of(Division::state(BrazilianState::MG), Division::municipality('Belo Horizonte', '3106200'), Division::district('Sede', '311860105'))];
        yield 'a state that is not a UF' => [static fn() => new Division(DivisionKind::State, 'Guanabara', 'GB')];
        yield 'a state without its UF' => [static fn() => new Division(DivisionKind::State, 'São Paulo')];
        yield 'a geocode short of a digit' => [static fn() => Division::municipality('Belo Horizonte', '310620')];
        yield 'a geocode with letters' => [static fn() => Division::subdistrict('Sede', '3106200050A')];
        yield 'a blank name' => [static fn() => Division::neighborhood('   ')];
        yield 'a name longer than the column' => [static fn() => Division::municipality(str_repeat('a', 81))];
        yield 'a blank local code' => [static fn() => new Division(DivisionKind::Neighborhood, 'Centro', ' ')];
        yield 'json that is not a list' => [static fn() => Divisions::fromJson('{"kind":"state"}')];
        yield 'json that is not json' => [static fn() => Divisions::fromJson('[{')];
        yield 'a division without a name' => [static fn() => Divisions::fromJson('[{"kind":"state","code":"SP"},{"kind":"municipality","code":null}]')];
        yield 'a kind nobody knows' => [static fn() => Divisions::fromJson('[{"kind":"state","code":"SP","name":"São Paulo"},{"kind":"county","code":null,"name":"Campinas"}]')];
    }

    /** @param Closure(): mixed $build */
    #[Test]
    #[DataProvider('invalidTerritories')]
    public function a_territory_out_of_order_or_with_wrong_codes_is_refused(Closure $build): void
    {
        $this->expectException(InvalidAddress::class);

        $build();
    }
}
