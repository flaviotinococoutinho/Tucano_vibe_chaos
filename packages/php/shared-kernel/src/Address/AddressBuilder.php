<?php

declare(strict_types=1);

namespace Tucano\SharedKernel\Address;

/**
 * Puts an address together one part at a time, in the order people say it,
 * and hands over an immutable Address on build(). Each part is checked when
 * it comes in, and build() checks the whole: nothing is missing and the
 * divisions follow the hierarchy. The builder itself is mutable on purpose:
 * it is the scaffolding, not the building.
 *
 *     Address::builder()
 *         ->thoroughfare('Rua', 'da Bahia')->number('1200')->complement('apto 42')
 *         ->state(BrazilianState::MG)->municipality('Belo Horizonte', '3106200')->neighborhood('Centro')
 *         ->postalCode('30160-011')
 *         ->build();
 */
final class AddressBuilder
{
    private ?Thoroughfare $thoroughfare = null;

    private ?string $number = null;

    private ?string $complement = null;

    /** @var list<Division> */
    private array $divisions = [];

    private ?PostalCode $postalCode = null;

    private ?Coordinates $coordinates = null;

    public function thoroughfare(string $type, string $name): self
    {
        $this->thoroughfare = Thoroughfare::of($type, $name);

        return $this;
    }

    public function number(string $number): self
    {
        $this->number = $number;

        return $this;
    }

    public function complement(?string $complement): self
    {
        $this->complement = $complement;

        return $this;
    }

    public function state(BrazilianState $state): self
    {
        return $this->division(Division::state($state));
    }

    public function municipality(string $name, ?string $geocode = null): self
    {
        return $this->division(Division::municipality($name, $geocode));
    }

    public function district(string $name, ?string $geocode = null): self
    {
        return $this->division(Division::district($name, $geocode));
    }

    public function subdistrict(string $name, ?string $geocode = null): self
    {
        return $this->division(Division::subdistrict($name, $geocode));
    }

    public function neighborhood(string $name): self
    {
        return $this->division(Division::neighborhood($name));
    }

    /** The next division down; the calls follow the hierarchy, from the state to the narrowest. */
    public function division(Division $division): self
    {
        $this->divisions[] = $division;

        return $this;
    }

    /** The whole territory at once, as a jsonb column or an event brings it. */
    public function divisions(Divisions $divisions): self
    {
        $this->divisions = iterator_to_array($divisions, false);

        return $this;
    }

    public function postalCode(string $postalCode): self
    {
        $this->postalCode = PostalCode::of($postalCode);

        return $this;
    }

    /** Both or none: a column pair or a form may leave them out, never half of them. */
    public function coordinates(?float $latitude, ?float $longitude): self
    {
        if (($latitude === null) !== ($longitude === null)) {
            throw InvalidAddress::because('Coordinates come as latitude and longitude together.');
        }
        $this->coordinates = $latitude === null || $longitude === null ? null : Coordinates::of($latitude, $longitude);

        return $this;
    }

    public function build(): Address
    {
        $missing = array_keys(array_filter([
            'thoroughfare' => $this->thoroughfare === null,
            'number' => $this->number === null,
            'CEP' => $this->postalCode === null,
        ]));
        if ($this->thoroughfare === null || $this->number === null || $this->postalCode === null) {
            throw InvalidAddress::because(sprintf('The address is missing its %s.', implode(', ', $missing)));
        }

        return Address::of($this->thoroughfare, $this->number, $this->complement, Divisions::of(...$this->divisions), $this->postalCode, $this->coordinates);
    }
}
