<?php

declare(strict_types=1);

namespace Logistics\Shipping\Domain\Parcel;

use Logistics\Shipping\Domain\Error\InvalidShipment;

/** Length, width and height in millimetres, each one a positive INTEGER. */
final readonly class Dimensions
{
    private const int MAX_MILLIMETRES = 2_147_483_647;

    private function __construct(public int $lengthMm, public int $widthMm, public int $heightMm) {}

    public static function ofMillimetres(int $length, int $width, int $height): self
    {
        foreach (['length' => $length, 'width' => $width, 'height' => $height] as $side => $millimetres) {
            self::assertInRange($side, $millimetres);
        }

        return new self($length, $width, $height);
    }

    /** The units go one on top of the other, so only the height grows. */
    public function stacked(Quantity $quantity): self
    {
        return self::ofMillimetres($this->lengthMm, $this->widthMm, $this->heightMm * $quantity->value);
    }

    private static function assertInRange(string $side, int $millimetres): void
    {
        if ($millimetres < 1 || $millimetres > self::MAX_MILLIMETRES) {
            throw InvalidShipment::because(sprintf('A %s goes from 1 to %d mm, got %d.', $side, self::MAX_MILLIMETRES, $millimetres));
        }
    }
}
