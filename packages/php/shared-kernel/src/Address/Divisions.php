<?php

declare(strict_types=1);

namespace Tucano\SharedKernel\Address;

use ArrayIterator;
use IteratorAggregate;
use JsonException;
use JsonSerializable;
use Traversable;

/**
 * The territory of an address, from the broadest division to the narrowest.
 * Every Brazilian address has a state and a municipality; district,
 * subdistrict and neighborhood come when they exist. Each kind shows up once,
 * in the order of the hierarchy, and an IBGE geocode starts with the geocode
 * of the division above it, so a municipality of São Paulo cannot sit in
 * Minas Gerais.
 *
 * @implements IteratorAggregate<int, Division>
 */
final readonly class Divisions implements IteratorAggregate, JsonSerializable
{
    /** @param non-empty-list<Division> $divisions */
    private function __construct(private array $divisions, private BrazilianState $state, private Division $municipality) {}

    public static function of(Division ...$divisions): self
    {
        $divisions = array_values($divisions);
        $state = $divisions[0] ?? null;
        $municipality = $divisions[1] ?? null;
        if ($state?->kind !== DivisionKind::State || $municipality?->kind !== DivisionKind::Municipality) {
            throw InvalidAddress::because('The divisions of an address start with its state and its municipality.');
        }
        $divisions = [$state, $municipality, ...array_slice($divisions, 2)];
        foreach (array_slice($divisions, 1) as $index => $division) {
            $above = $divisions[$index];
            if ($division->kind->rank() <= $above->kind->rank()) {
                throw InvalidAddress::because(sprintf('The divisions go from the broadest to the narrowest, each kind once: a %s cannot come after a %s.', $division->kind->value, $above->kind->value));
            }
        }
        $uf = BrazilianState::from((string) $state->code);
        self::assertGeocodes($divisions, $uf);

        return new self($divisions, $uf, $municipality);
    }

    /** @param array<mixed> $divisions the shape toArray writes */
    public static function fromArray(array $divisions): self
    {
        if (!array_is_list($divisions)) {
            throw InvalidAddress::because('The divisions are a list, from the broadest to the narrowest.');
        }

        return self::of(...array_map(
            static fn(mixed $division): Division => is_array($division) ? Division::fromArray($division) : throw InvalidAddress::because('Each division is an object.'),
            $divisions,
        ));
    }

    /** Reads back what toJson wrote, as a jsonb column keeps it. */
    public static function fromJson(string $json): self
    {
        try {
            $divisions = json_decode($json, true, 3, JSON_THROW_ON_ERROR);
        } catch (JsonException $unreadable) {
            throw InvalidAddress::because(sprintf('The divisions are not JSON: %s.', $unreadable->getMessage()));
        }

        return is_array($divisions) ? self::fromArray($divisions) : throw InvalidAddress::because('The divisions are a list.');
    }

    public function state(): BrazilianState
    {
        return $this->state;
    }

    public function municipality(): Division
    {
        return $this->municipality;
    }

    public function find(DivisionKind $kind): ?Division
    {
        foreach ($this->divisions as $division) {
            if ($division->kind === $kind) {
                return $division;
            }
        }

        return null;
    }

    /** @return list<Division> the divisions inside a kind, broadest first: below(Municipality) are district, subdistrict and neighborhood */
    public function below(DivisionKind $kind): array
    {
        return array_values(array_filter($this->divisions, static fn(Division $division): bool => $division->kind->rank() > $kind->rank()));
    }

    /** The same territory up to a kind: upTo(Municipality) leaves the state and the municipality. */
    public function upTo(DivisionKind $kind): self
    {
        return self::of(...array_filter($this->divisions, static fn(Division $division): bool => $division->kind->rank() <= $kind->rank()));
    }

    /** @return Traversable<int, Division> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->divisions);
    }

    /** @return list<array{kind: string, code: ?string, name: string}> */
    public function toArray(): array
    {
        return array_map(static fn(Division $division): array => $division->toArray(), $this->divisions);
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /** @return list<array{kind: string, code: ?string, name: string}> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** @param non-empty-list<Division> $divisions */
    private static function assertGeocodes(array $divisions, BrazilianState $state): void
    {
        $above = ['code' => $state->ibgeCode(), 'name' => $state->officialName()];
        foreach ($divisions as $division) {
            if ($division->kind->geocodeLength() === null || $division->code === null) {
                continue;
            }
            if (!str_starts_with($division->code, $above['code'])) {
                throw InvalidAddress::because(sprintf('The IBGE geocode %s of %s is not inside %s (%s).', $division->code, $division->name, $above['name'], $above['code']));
            }
            $above = ['code' => $division->code, 'name' => $division->name];
        }
    }
}
