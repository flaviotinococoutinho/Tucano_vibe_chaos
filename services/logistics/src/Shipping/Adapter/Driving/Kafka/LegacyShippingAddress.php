<?php

declare(strict_types=1);

namespace Logistics\Shipping\Adapter\Driving\Kafka;

use Tucano\SharedKernel\Address\Address;
use Tucano\SharedKernel\Address\BrazilianState;
use Tucano\SharedKernel\Messaging\EventFields;

/**
 * The shipping address as commerce.orders.v1 carries it, from before ADR 0020:
 * a street with the type inside it, the district that was really the
 * neighborhood, and the city. It is read until that topic drains; then this
 * class goes away with it.
 */
final readonly class LegacyShippingAddress
{
    /** First words that name a thoroughfare type, abbreviations included; the same list as the migration. */
    private const array TYPES = [
        'rua' => 'Rua', 'r' => 'Rua', 'avenida' => 'Avenida', 'av' => 'Avenida', 'rodovia' => 'Rodovia', 'rod' => 'Rodovia',
        'estrada' => 'Estrada', 'est' => 'Estrada', 'travessa' => 'Travessa', 'tv' => 'Travessa', 'alameda' => 'Alameda', 'al' => 'Alameda',
        'praça' => 'Praça', 'praca' => 'Praça', 'pça' => 'Praça', 'largo' => 'Largo', 'ladeira' => 'Ladeira', 'viela' => 'Viela', 'beco' => 'Beco',
    ];

    private function __construct() {}

    public static function read(EventFields $address): Address
    {
        [$type, $name] = self::thoroughfare($address->text('street'));

        return Address::builder()
            ->thoroughfare($type, $name)
            ->number($address->text('number'))
            ->complement($address->optionalText('complement'))
            ->state(BrazilianState::from($address->text('state')))
            ->municipality($address->text('city'))
            ->neighborhood($address->text('district'))
            ->postalCode($address->text('postalCode'))
            ->coordinates($address->optionalNumber('latitude'), $address->optionalNumber('longitude'))
            ->build();
    }

    /** @return array{string, string} the type and the name; a street that does not start with a known type is a Rua */
    private static function thoroughfare(string $street): array
    {
        $street = trim($street);
        $words = preg_split('/\s+/u', $street, 2);
        $type = self::TYPES[mb_strtolower(rtrim($words[0] ?? '', '.'))] ?? null;

        return $type === null || !isset($words[1]) ? ['Rua', $street] : [$type, $words[1]];
    }
}
