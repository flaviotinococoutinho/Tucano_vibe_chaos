<?php

declare(strict_types=1);

namespace Logistics\Timeline\Domain;

/** Where the parcels go, as far as the public may know: the municipality and its UF, never the address. */
final readonly class Place
{
    private function __construct(public string $municipality, public string $state) {}

    public static function of(string $municipality, string $state): self
    {
        return new self($municipality, $state);
    }

    /** @return array{municipality: string, state: string} */
    public function toArray(): array
    {
        return ['municipality' => $this->municipality, 'state' => $this->state];
    }
}
