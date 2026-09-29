<?php

declare(strict_types=1);

namespace Commerce\Ordering\Domain\Customer;

/** Who bought, frozen in the order: later profile changes do not rewrite history. */
final readonly class Customer
{
    private function __construct(
        public CustomerId $id,
        public PersonName $name,
        public EmailAddress $email,
    ) {}

    public static function of(CustomerId $id, PersonName $name, EmailAddress $email): self
    {
        return new self($id, $name, $email);
    }

    public function is(CustomerId $id): bool
    {
        return $this->id->equals($id);
    }
}
