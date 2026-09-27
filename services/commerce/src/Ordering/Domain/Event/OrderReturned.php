<?php

declare(strict_types=1);

namespace Commerce\Ordering\Domain\Event;

final readonly class OrderReturned extends OrderEvent
{
    protected function fact(): string
    {
        return 'returned';
    }

    protected function details(): array
    {
        return [];
    }
}
