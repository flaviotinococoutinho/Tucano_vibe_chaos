<?php

declare(strict_types=1);

namespace Logistics\Shipping\Domain\Destination;

use Logistics\Shipping\Domain\Error\InvalidShipment;

/**
 * Where the parcels go, frozen from the order. The limits are the ones of the
 * order.paid contract and of the dest_* columns.
 */
final readonly class Destination
{
    private const array LIMITS = ['street' => 160, 'number' => 16, 'district' => 80, 'city' => 80];

    private const int MAX_COMPLEMENT = 80;

    public function __construct(
        public string $street,
        public string $number,
        public ?string $complement,
        public string $district,
        public string $city,
        public BrazilianState $state,
        public PostalCode $postalCode,
        public ?Coordinates $coordinates = null,
    ) {
        foreach (['street' => $street, 'number' => $number, 'district' => $district, 'city' => $city] as $field => $value) {
            self::assertFits($field, $value);
        }
        if ($complement !== null && mb_strlen($complement) > self::MAX_COMPLEMENT) {
            throw InvalidShipment::because(sprintf('The complement of the destination takes up to %d characters.', self::MAX_COMPLEMENT));
        }
    }

    private static function assertFits(string $field, string $value): void
    {
        if (trim($value) === '' || mb_strlen($value) > self::LIMITS[$field]) {
            throw InvalidShipment::because(sprintf('The %s of the destination takes 1 to %d characters.', $field, self::LIMITS[$field]));
        }
    }
}
