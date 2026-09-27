<?php

declare(strict_types=1);

namespace Tucano\FeatureFlags;

/**
 * Who is asking: the targeting key drives percentage rollouts (same customer,
 * same bucket) and the attributes feed targeting rules such as e-mail domains.
 */
final readonly class FlagContext
{
    /** @param array<string, bool|int|float|string> $attributes */
    public function __construct(
        public ?string $targetingKey = null,
        public array $attributes = [],
    ) {}

    public static function forCustomer(string $customerId, ?string $email = null): self
    {
        return new self($customerId, $email === null ? [] : ['email' => $email]);
    }

    public function with(string $attribute, bool|int|float|string $value): self
    {
        return new self($this->targetingKey, [...$this->attributes, $attribute => $value]);
    }

    /** Stable key for caching one evaluation per flag and context. */
    public function fingerprint(): string
    {
        $attributes = $this->attributes;
        ksort($attributes);

        return hash('xxh128', serialize([$this->targetingKey, $attributes]));
    }
}
