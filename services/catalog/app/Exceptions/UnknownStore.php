<?php

declare(strict_types=1);

namespace App\Exceptions;

/** A new product names a store the platform does not host. */
final class UnknownStore extends InvalidField
{
    public static function withSlug(string $slug): self
    {
        return new self(sprintf('Store "%s" does not exist.', $slug));
    }

    public function field(): string
    {
        return 'store';
    }
}
