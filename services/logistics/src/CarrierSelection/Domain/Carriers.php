<?php

declare(strict_types=1);

namespace Logistics\CarrierSelection\Domain;

use Closure;

/**
 * First-class collection of carriers. The rules narrow it down step by step
 * and take the smallest carrier left, so the selection reads like the policy.
 */
final readonly class Carriers
{
    /** @var list<Carrier> */
    private array $carriers;

    public function __construct(Carrier ...$carriers)
    {
        $this->carriers = array_values($carriers);
    }

    public function ofKind(CarrierKind $kind): self
    {
        return $this->filter(static fn(Carrier $carrier): bool => $carrier->kind === $kind);
    }

    public function only(string $code): self
    {
        return $this->filter(static fn(Carrier $carrier): bool => $carrier->is($code));
    }

    public function without(string $code): self
    {
        return $this->filter(static fn(Carrier $carrier): bool => !$carrier->is($code));
    }

    public function thatCarry(Consignment $consignment): self
    {
        return $this->filter(static fn(Carrier $carrier): bool => $carrier->carries($consignment));
    }

    /** The one with the lowest weight limit, so the bigger trucks stay free for heavier loads; the code breaks a tie. */
    public function smallest(): ?Carrier
    {
        $carriers = $this->carriers;
        usort($carriers, static fn(Carrier $a, Carrier $b): int => [$a->maxWeightGrams, $a->code] <=> [$b->maxWeightGrams, $b->code]);

        return $carriers[0] ?? null;
    }

    /** @param Closure(Carrier): bool $keep */
    private function filter(Closure $keep): self
    {
        return new self(...array_filter($this->carriers, $keep));
    }
}
