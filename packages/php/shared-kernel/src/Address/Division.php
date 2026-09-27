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
    public const int MAX_CODE = 20;

    private function __construct(public DivisionKind $kind, public string $name, public ?string $code) {}

    public static function of(DivisionKind $kind, string $name, ?string $code = null): self
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > self::MAX_NAME) {
            throw InvalidAddress::because(sprintf('The name of the %s takes 1 to %d characters.', $kind->value, self::MAX_NAME));
        }
        self::assertCode($kind, $code);

        return new self($kind, $name, $code);
    }

    public static function state(BrazilianState $state): self
    {
        return new self(DivisionKind::State, $state->officialName(), $state->value);
    }

    public static function municipality(string $name, ?string $geocode = null): self
    {
        return self::of(DivisionKind::Municipality, $name, $geocode);
    }

    public static function district(string $name, ?string $geocode = null): self
    {
        return self::of(DivisionKind::District, $name, $geocode);
    }

    public static function subdistrict(string $name, ?string $geocode = null): self
    {
        return self::of(DivisionKind::Subdistrict, $name, $geocode);
    }

    public static function neighborhood(string $name): self
    {
        return self::of(DivisionKind::Neighborhood, $name);
    }

    /** @param array<mixed> $fields the shape toArray writes */
    public static function fromArray(array $fields): self
    {
        $kind = $fields['kind'] ?? null;
        $name = $fields['name'] ?? null;
        $code = $fields['code'] ?? null;
        if (!is_string($kind) || !is_string($name) || !(is_string($code) || $code === null)) {
            throw InvalidAddress::because('A division is a kind, a name and a code or null, all as text.');
        }
        $known = DivisionKind::tryFrom($kind) ?? throw InvalidAddress::because(sprintf('"%s" is not a kind of division.', $kind));

        return self::of($known, $name, $code);
    }

    /** @return array{kind: string, code: ?string, name: string} */
    public function toArray(): array
    {
        return ['kind' => $this->kind->value, 'code' => $this->code, 'name' => $this->name];
    }

    /** @return array{kind: string, code: ?string, name: string} */
    public function jsonSerialize(): array
    {
        return $this->toArray();
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
        if ($digits === null && (trim($code) === '' || strlen($code) > self::MAX_CODE)) {
            throw InvalidAddress::because(sprintf('The code of a %s takes 1 to %d characters.', $kind->value, self::MAX_CODE));
        }
    }
}
