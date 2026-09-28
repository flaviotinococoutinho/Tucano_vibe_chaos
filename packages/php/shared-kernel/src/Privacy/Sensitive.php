<?php

declare(strict_types=1);

namespace Tucano\SharedKernel\Privacy;

use JsonSerializable;
use LogicException;
use Stringable;

/**
 * A value that must not leak by accident: a protection proxy around personal data (LGPD)
 * and what charges a card (PCI DSS). Printed, logged, dumped or encoded as JSON, it shows
 * the mask of its category. The value itself comes out only through reveal(), a word easy
 * to find in a review, and PHP serialization refuses it, so it never lands in a cache or
 * a queue without someone deciding so.
 *
 * It stops accidents, not intent: var_export() and an (array) cast still see inside.
 */
final readonly class Sensitive implements Stringable, JsonSerializable
{
    private function __construct(private string $value, public DataCategory $category) {}

    public static function of(string $value, DataCategory $category): self
    {
        return new self($value, $category);
    }

    /** The value, for the places that must have it: the row, the event, the label, the PSP. */
    public function reveal(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->category->mask($this->value);
    }

    public function jsonSerialize(): string
    {
        return $this->__toString();
    }

    /** What var_dump() and print_r() show. @return array{category: string, value: string} */
    public function __debugInfo(): array
    {
        return ['category' => $this->category->value, 'value' => $this->__toString()];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException(sprintf(
            'A %s is sensitive (%s) and is not serialized; store what reveal() gives, on purpose.',
            $this->category->value,
            $this->category->regime(),
        ));
    }
}
