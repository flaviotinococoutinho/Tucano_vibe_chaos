<?php

declare(strict_types=1);

namespace Tucano\SharedKernel\Address;

use JsonSerializable;

/**
 * Where parcels are delivered: the thoroughfare and the number on it, the
 * complement, the territorial divisions, the CEP and, when the customer shares
 * them, the coordinates. The number is text, because it is not always a
 * number: KM 500 on a highway, S/N on a lot without one, 120-A.
 *
 * Built part by part with Address::builder(), read back with fromArray and
 * written out with toArray, the shape of the API and of the events.
 */
final readonly class Address implements JsonSerializable
{
    public const int MAX_NUMBER = 20;

    public const int MAX_COMPLEMENT = 80;

    private function __construct(
        public Thoroughfare $thoroughfare,
        public string $number,
        public ?string $complement,
        public Divisions $divisions,
        public PostalCode $postalCode,
        public ?Coordinates $coordinates,
    ) {}

    public static function of(
        Thoroughfare $thoroughfare,
        string $number,
        ?string $complement,
        Divisions $divisions,
        PostalCode $postalCode,
        ?Coordinates $coordinates = null,
    ): self {
        $number = trim($number);
        if ($number === '' || mb_strlen($number) > self::MAX_NUMBER) {
            throw InvalidAddress::because(sprintf('The number on the thoroughfare takes 1 to %d characters; S/N stands for none.', self::MAX_NUMBER));
        }
        $complement = $complement === null ? null : trim($complement);
        if ($complement !== null && mb_strlen($complement) > self::MAX_COMPLEMENT) {
            throw InvalidAddress::because(sprintf('The complement takes up to %d characters.', self::MAX_COMPLEMENT));
        }

        return new self($thoroughfare, $number, $complement === '' ? null : $complement, $divisions, $postalCode, $coordinates);
    }

    public static function builder(): AddressBuilder
    {
        return new AddressBuilder();
    }

    /** @param array<mixed> $fields the shape toArray writes: what the API takes and the events carry */
    public static function fromArray(array $fields): self
    {
        $thoroughfare = $fields['thoroughfare'] ?? null;
        $type = is_array($thoroughfare) ? $thoroughfare['type'] ?? null : null;
        $name = is_array($thoroughfare) ? $thoroughfare['name'] ?? null : null;
        $number = $fields['number'] ?? null;
        $complement = $fields['complement'] ?? null;
        $divisions = $fields['divisions'] ?? null;
        $postalCode = $fields['postalCode'] ?? null;
        if (!is_string($type) || !is_string($name) || !is_string($number) || !(is_string($complement) || $complement === null) || !is_array($divisions) || !is_string($postalCode)) {
            throw InvalidAddress::because('An address has a thoroughfare with a type and a name, a number, a complement or null, the divisions and a CEP.');
        }

        return self::builder()
            ->thoroughfare($type, $name)
            ->number($number)
            ->complement($complement)
            ->divisions(Divisions::fromArray($divisions))
            ->postalCode($postalCode)
            ->coordinates(self::coordinate($fields, 'latitude'), self::coordinate($fields, 'longitude'))
            ->build();
    }

    public function state(): BrazilianState
    {
        return $this->divisions->state();
    }

    public function municipality(): Division
    {
        return $this->divisions->municipality();
    }

    /** The line a label or an e-mail starts with: Rua da Bahia, 1200 - apto 42. */
    public function thoroughfareLine(): string
    {
        $line = sprintf('%s, %s', $this->thoroughfare, $this->number);

        return $this->complement === null ? $line : sprintf('%s - %s', $line, $this->complement);
    }

    /** @return array{thoroughfare: array{type: string, name: string}, number: string, complement: ?string, divisions: list<array{kind: string, code: ?string, name: string}>, postalCode: string, latitude: ?float, longitude: ?float} */
    public function toArray(): array
    {
        return [
            'thoroughfare' => $this->thoroughfare->toArray(),
            'number' => $this->number,
            'complement' => $this->complement,
            'divisions' => $this->divisions->toArray(),
            'postalCode' => (string) $this->postalCode,
            'latitude' => $this->coordinates?->latitude,
            'longitude' => $this->coordinates?->longitude,
        ];
    }

    /** @return array{thoroughfare: array{type: string, name: string}, number: string, complement: ?string, divisions: list<array{kind: string, code: ?string, name: string}>, postalCode: string, latitude: ?float, longitude: ?float} */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** @param array<mixed> $fields */
    private static function coordinate(array $fields, string $name): ?float
    {
        $value = $fields[$name] ?? null;

        return match (true) {
            $value === null => null,
            is_numeric($value) => (float) $value,
            default => throw InvalidAddress::because(sprintf('The %s is a number.', $name)),
        };
    }
}
