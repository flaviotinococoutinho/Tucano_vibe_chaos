<?php

declare(strict_types=1);

namespace App\Services;

use Random\Randomizer;

/**
 * A cache TTL spread around its base value. Keys cached in the same burst (after a
 * deploy or a flush) then expire at different moments instead of all at once.
 */
final readonly class JitteredTtl
{
    public function __construct(
        private int $seconds,
        private float $spread,
        private Randomizer $random = new Randomizer(),
    ) {}

    public function seconds(): int
    {
        $jitter = (int) round($this->seconds * $this->spread);

        return $this->random->getInt($this->seconds - $jitter, $this->seconds + $jitter);
    }
}
