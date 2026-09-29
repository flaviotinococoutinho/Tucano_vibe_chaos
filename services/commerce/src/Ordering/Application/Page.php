<?php

declare(strict_types=1);

namespace Commerce\Ordering\Application;

use InvalidArgumentException;

/** Which part of a list to read: the page number, counted from 1, and how many items a page holds. */
final readonly class Page
{
    public const int DEFAULT_SIZE = 10;

    public const int MAX_SIZE = 50;

    private function __construct(public int $number, public int $size) {}

    public static function of(int $number, int $size = self::DEFAULT_SIZE): self
    {
        if ($number < 1 || $size < 1 || $size > self::MAX_SIZE) {
            throw new InvalidArgumentException(sprintf('Pages count from 1 and hold 1 to %d items, not page %d of %d.', self::MAX_SIZE, $number, $size));
        }

        return new self($number, $size);
    }

    /** How many items come before this page. A page past the largest integer is past every list, and stays there. */
    public function offset(): int
    {
        return min($this->number - 1, intdiv(PHP_INT_MAX, $this->size)) * $this->size;
    }
}
