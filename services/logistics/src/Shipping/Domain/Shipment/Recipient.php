<?php

declare(strict_types=1);

namespace Logistics\Shipping\Domain\Shipment;

use Logistics\Shipping\Domain\Error\InvalidShipment;
use Tucano\SharedKernel\Privacy\DataCategory;
use Tucano\SharedKernel\Privacy\Sensitive;

/**
 * Who receives the parcels, frozen from the order: a name (VARCHAR(120)) and an e-mail
 * (VARCHAR(254)). Both are personal data under the LGPD, so they travel as Sensitive and
 * show only a mask when printed by accident.
 */
final readonly class Recipient
{
    private const int MAX_NAME = 120;

    private const int MAX_EMAIL = 254;

    private function __construct(public Sensitive $name, public Sensitive $email) {}

    public static function of(string $name, string $email): self
    {
        $name = trim($name);
        $email = trim($email);
        if ($name === '' || mb_strlen($name) > self::MAX_NAME) {
            throw InvalidShipment::because(sprintf('A recipient needs a name of 1 to %d characters.', self::MAX_NAME));
        }
        if ($email === '' || strlen($email) > self::MAX_EMAIL) {
            throw InvalidShipment::because(sprintf('A recipient needs an e-mail of 1 to %d characters.', self::MAX_EMAIL));
        }

        return new self(Sensitive::of($name, DataCategory::PersonName), Sensitive::of($email, DataCategory::Email));
    }
}
