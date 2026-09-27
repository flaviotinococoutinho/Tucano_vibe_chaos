<?php

declare(strict_types=1);

namespace Commerce\Ordering\Domain\Order;

use ArrayIterator;
use Commerce\Ordering\Domain\Error\InvalidOrder;
use Countable;
use IteratorAggregate;
use Traversable;
use Tucano\SharedKernel\Money\Money;

/**
 * First-class collection: the rules about lines (at least one, one line per
 * SKU, one currency) live here instead of being repeated around the code.
 *
 * @implements IteratorAggregate<int, OrderLine>
 */
final readonly class OrderLines implements IteratorAggregate, Countable
{
    /** @param list<OrderLine> $lines */
    private function __construct(private array $lines) {}

    public static function of(OrderLine ...$lines): self
    {
        if ($lines === []) {
            throw InvalidOrder::because('An order needs at least one line.');
        }
        $skus = array_map(static fn(OrderLine $line): string => (string) $line->sku, $lines);
        if (count(array_unique($skus)) !== count($skus)) {
            throw InvalidOrder::because('Each SKU can appear in only one line; change the quantity instead.');
        }

        return new self(array_values($lines));
    }

    public function total(): Money
    {
        return array_reduce(
            $this->lines,
            static fn(Money $total, OrderLine $line): Money => $total->add($line->subtotal()),
            Money::zero($this->lines[0]->unitPrice->currency()),
        );
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->lines);
    }

    public function count(): int
    {
        return count($this->lines);
    }
}
