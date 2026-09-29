<?php

declare(strict_types=1);

namespace App\Exceptions;

final class UnknownCategory extends InvalidField
{
    public static function withSlug(string $slug): self
    {
        return new self(sprintf('Category "%s" does not exist.', $slug));
    }

    public function field(): string
    {
        return 'category';
    }
}
