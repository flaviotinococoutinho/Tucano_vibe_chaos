<?php

declare(strict_types=1);

namespace Tucano\SharedKernel\Address;

use JsonSerializable;

/**
 * Where parcels are delivered: the thoroughfare and the number on it, the
 * complement, the territorial divisions, the CEP and, when the customer shares
 * them, the coordinates. The number is text, because it is not always a
 * number: KM 100 on a highway, S/N on a lot without one, 120-A.
 */
final readonly class Address implements JsonSerializable
{
    public const int MAX_NUMBER = 20;

    public const int MAX_COMPLEMENT = 80;

    public string $number;

    public ?string $complement;

    public function __construct(
        public Thoroughfare $thoroughfare,
        string $number,
        ?string $complement,
        public Divisions $divisions,
        public PostalCode $postalCode,
        public ?Coordinates $coordinates = null,
    ) {
        $this->number = trim($number);
        if ($this->number === '' || mb_strlen($this->number) > self::MAX_NUMBER) {
            throw InvalidAddress::because(sprintf('The number on the thoroughfare takes 1 to %d characters; S/N stands for none.', self::MAX_NUMBER));
        }
        $complement = $complement === null ? null : trim($complement);
        if ($complement !== null && mb_strlen($complement) > self::MAX_COMPLEMENT) {
            throw InvalidAddress::because(sprintf('The complement takes up to %d characters.', self::MAX_COMPLEMENT));
        }
        $this->complement = $complement === '' ? null : $complement;
    }

    public function state(): BrazilianState
    {
        return $this->divisions->state();
    }

    /**
     * The shape of the address in the events and in the API.
     *
     * @return array{thoroughfare: array{type: string, name: string}, number: string, complement: ?string, divisions: list<array{kind: string, code: ?string, name: string}>, postalCode: string, latitude: ?float, longitude: ?float}
     */
    public function jsonSerialize(): array
    {
        return [
            'thoroughfare' => $this->thoroughfare->jsonSerialize(),
            'number' => $this->number,
            'complement' => $this->complement,
            'divisions' => $this->divisions->jsonSerialize(),
            'postalCode' => (string) $this->postalCode,
            'latitude' => $this->coordinates?->latitude,
            'longitude' => $this->coordinates?->longitude,
        ];
    }
}
