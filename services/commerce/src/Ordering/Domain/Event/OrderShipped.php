<?php

declare(strict_types=1);

namespace Commerce\Ordering\Domain\Event;

final readonly class OrderShipped extends OrderEvent
{
    protected function fact(): string
    {
        return 'shipped';
    }

    protected function details(): array
    {
        return [];
    }
}
