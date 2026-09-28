<?php

declare(strict_types=1);

namespace App\Models;

use JsonSerializable;

/** Size of the package in millimeters, which logistics uses to pick a carrier. */
final readonly class Dimensions implements JsonSerializable
{
    private function __construct(
        public int $lengthMm,
        public int $widthMm,
        public int $heightMm,
    ) {}

    public static function ofMillimetres(int $lengthMm, int $widthMm, int $heightMm): self
    {
        return new self($lengthMm, $widthMm, $heightMm);
    }

    /** @param array{lengthMm: int, widthMm: int, heightMm: int} $dimensions */
    public static function fromArray(array $dimensions): self
    {
        return new self($dimensions['lengthMm'], $dimensions['widthMm'], $dimensions['heightMm']);
    }

    public function equals(self $other): bool
    {
        return $other->lengthMm === $this->lengthMm
            && $other->widthMm === $this->widthMm
            && $other->heightMm === $this->heightMm;
    }

    /** @return array{lengthMm: int, widthMm: int, heightMm: int} */
    public function toArray(): array
    {
        return ['lengthMm' => $this->lengthMm, 'widthMm' => $this->widthMm, 'heightMm' => $this->heightMm];
    }

    /** @return array{lengthMm: int, widthMm: int, heightMm: int} */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
