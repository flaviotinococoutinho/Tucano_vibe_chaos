<?php

declare(strict_types=1);

namespace App\Models;

use JsonSerializable;

/** Size of the package in millimeters, which logistics uses to pick a carrier. */
final readonly class Dimensions implements JsonSerializable
{
    public function __construct(
        public int $lengthMm,
        public int $widthMm,
        public int $heightMm,
    ) {}

    public function equals(self $other): bool
    {
        return $other->lengthMm === $this->lengthMm
            && $other->widthMm === $this->widthMm
            && $other->heightMm === $this->heightMm;
    }

    /** @return array{lengthMm: int, widthMm: int, heightMm: int} */
    public function jsonSerialize(): array
    {
        return ['lengthMm' => $this->lengthMm, 'widthMm' => $this->widthMm, 'heightMm' => $this->heightMm];
    }
}
