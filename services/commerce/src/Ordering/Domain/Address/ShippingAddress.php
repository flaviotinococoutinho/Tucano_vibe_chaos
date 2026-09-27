<?php

declare(strict_types=1);

namespace Commerce\Ordering\Domain\Address;

use Commerce\Ordering\Domain\Error\InvalidOrder;

final readonly class ShippingAddress
{
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
            self::assertFilled($field, $value);
        }
    }

    private static function assertFilled(string $field, string $value): void
    {
        if (trim($value) === '') {
            throw InvalidOrder::because(sprintf('The shipping address needs a %s.', $field));
        }
    }
}
