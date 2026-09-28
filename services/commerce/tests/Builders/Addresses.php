<?php

declare(strict_types=1);

namespace Tests\Builders;

use Tucano\SharedKernel\Address\Address;
use Tucano\SharedKernel\Address\BrazilianState;

/** The addresses the tests ship to. Their toArray() is also what the checkout API takes. */
final class Addresses
{
    public static function paulista(): Address
    {
        return Address::builder()
            ->thoroughfare('Avenida', 'Paulista')->number('1000')->complement('Apto 12')
            ->state(BrazilianState::SP)->municipality('São Paulo', '3550308')->neighborhood('Bela Vista')
            ->postalCode('01310-100')
            ->build();
    }

    public static function bahia(): Address
    {
        return Address::builder()
            ->thoroughfare('Rua', 'da Bahia')->number('1200')
            ->state(BrazilianState::MG)->municipality('Belo Horizonte', '3106200')->neighborhood('Centro')
            ->postalCode('30160-011')
            ->build();
    }
}
