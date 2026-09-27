<?php

declare(strict_types=1);

namespace Tucano\SharedKernel\Address;

use JsonSerializable;
use Stringable;

/**
 * The public way the address is on, as the type and the name: Rua da Bahia,
 * Avenida Afonso Pena, Rodovia Fernão Dias, Travessa, Alameda, Praça. The type
 * is text, not an enum: no rule depends on it, and each country has its list.
 */
final readonly class Thoroughfare implements JsonSerializable, Stringable
{
    public const int MAX_TYPE = 30;

    public const int MAX_NAME = 160;

    public string $type;

    public string $name;

    public function __construct(string $type, string $name)
    {
        $this->type = self::fitted('type', $type, self::MAX_TYPE);
        $this->name = self::fitted('name', $name, self::MAX_NAME);
    }

    /** @return array{type: string, name: string} */
    public function jsonSerialize(): array
    {
        return ['type' => $this->type, 'name' => $this->name];
    }

    public function __toString(): string
    {
        return $this->type . ' ' . $this->name;
    }

    private static function fitted(string $field, string $value, int $limit): string
    {
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > $limit) {
            throw InvalidAddress::because(sprintf('The %s of the thoroughfare takes 1 to %d characters.', $field, $limit));
        }

        return $value;
    }
}
