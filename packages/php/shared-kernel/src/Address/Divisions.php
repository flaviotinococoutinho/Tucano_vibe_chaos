<?php

declare(strict_types=1);

namespace Tucano\SharedKernel\Address;

use ArrayIterator;
use IteratorAggregate;
use JsonException;
use JsonSerializable;
use Traversable;
use ValueError;

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

    /** Reads back what jsonSerialize wrote, as a jsonb column keeps it. */
    public static function fromJson(string $json): self
    {
        try {
            $divisions = json_decode($json, true, 3, JSON_THROW_ON_ERROR);
        } catch (JsonException $unreadable) {
            throw InvalidAddress::because(sprintf('The divisions are not JSON: %s.', $unreadable->getMessage()));
        }
        if (!is_array($divisions) || !array_is_list($divisions)) {
            throw InvalidAddress::because('The divisions are a list.');
        }

        return self::of(...array_map(self::division(...), $divisions));
    }

    public function state(): BrazilianState
    {
        return $this->state;
    }

    public function municipality(): Division
    {
        return $this->municipality;
    }

    /** The smallest place the address names: the neighborhood, when there is one. */
    public function narrowest(): Division
    {
        return $this->divisions[array_key_last($this->divisions)];
    }

    /** The same territory up to a kind: upTo(Municipality) leaves the state and the municipality. */
    public function upTo(DivisionKind $kind): self
    {
        $kept = array_values(array_filter($this->divisions, static fn(Division $division): bool => $division->kind->rank() <= $kind->rank()));

        return self::of(...$kept);
    }

    /** @return Traversable<int, Division> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->divisions);
    }

    /** @return list<array{kind: string, code: ?string, name: string}> */
    public function jsonSerialize(): array
    {
        return array_map(static fn(Division $division): array => $division->jsonSerialize(), $this->divisions);
    }

    private static function division(mixed $fields): Division
    {
        $kind = is_array($fields) ? $fields['kind'] ?? null : null;
        $name = is_array($fields) ? $fields['name'] ?? null : null;
        $code = is_array($fields) ? $fields['code'] ?? null : null;
        if (!is_string($kind) || !is_string($name) || ($code !== null && !is_string($code))) {
            throw InvalidAddress::because('A division has a kind, a name and a code or null.');
        }
        try {
            return new Division(DivisionKind::from($kind), $name, $code);
        } catch (ValueError) {
            throw InvalidAddress::because(sprintf('"%s" is not a kind of division.', $kind));
        }
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
