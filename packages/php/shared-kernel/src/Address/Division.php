<?php

declare(strict_types=1);

namespace Tucano\SharedKernel\Address;

use JsonSerializable;

/**
 * One level of the territory of an address: its kind, the name people write
 * and, when it has one, the official code. Rules read the code (the UF of a
 * state, the IBGE geocode of a municipality); people read the name.
 */
final readonly class Division implements JsonSerializable
{
    public const int MAX_NAME = 80;

    /** A neighborhood has no national code; the one its municipality gives it fits here. */
    private const int MAX_LOCAL_CODE = 20;

    public string $name;

    public function __construct(public DivisionKind $kind, string $name, public ?string $code = null)
    {
        $this->name = trim($name);
        if ($this->name === '' || mb_strlen($this->name) > self::MAX_NAME) {
            throw InvalidAddress::because(sprintf('The name of the %s takes 1 to %d characters.', $kind->value, self::MAX_NAME));
        }
        self::assertCode($kind, $code);
    }

    public static function state(BrazilianState $state): self
    {
        return new self(DivisionKind::State, $state->officialName(), $state->value);
    }

    public static function municipality(string $name, ?string $geocode = null): self
    {
        return new self(DivisionKind::Municipality, $name, $geocode);
    }

    public static function district(string $name, ?string $geocode = null): self
    {
        return new self(DivisionKind::District, $name, $geocode);
    }

    public static function subdistrict(string $name, ?string $geocode = null): self
    {
        return new self(DivisionKind::Subdistrict, $name, $geocode);
    }

    public static function neighborhood(string $name): self
    {
        return new self(DivisionKind::Neighborhood, $name);
    }

    /** @return array{kind: string, code: ?string, name: string} */
    public function jsonSerialize(): array
    {
        return ['kind' => $this->kind->value, 'code' => $this->code, 'name' => $this->name];
    }

    private static function assertCode(DivisionKind $kind, ?string $code): void
    {
        if ($kind === DivisionKind::State) {
            if ($code === null || BrazilianState::tryFrom($code) === null) {
                throw InvalidAddress::because(sprintf('A state is known by its UF, and "%s" is not one.', $code ?? ''));
            }

            return;
        }
        if ($code === null) {
            return;
        }
        $digits = $kind->geocodeLength();
        if ($digits !== null && preg_match(sprintf('/^\d{%d}$/', $digits), $code) !== 1) {
            throw InvalidAddress::because(sprintf('The IBGE geocode of a %s has %d digits, and "%s" does not.', $kind->value, $digits, $code));
        }
        if ($digits === null && (trim($code) === '' || strlen($code) > self::MAX_LOCAL_CODE)) {
            throw InvalidAddress::because(sprintf('The code of a %s takes 1 to %d characters.', $kind->value, self::MAX_LOCAL_CODE));
        }
    }
}
