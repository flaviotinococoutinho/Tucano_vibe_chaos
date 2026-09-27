<?php

declare(strict_types=1);

namespace Commerce\Ordering\Domain\Customer;

/** Who bought, frozen in the order: later profile changes do not rewrite history. */
final readonly class Customer
{
    public function __construct(
        public CustomerId $id,
        public PersonName $name,
        public EmailAddress $email,
    ) {}
}
