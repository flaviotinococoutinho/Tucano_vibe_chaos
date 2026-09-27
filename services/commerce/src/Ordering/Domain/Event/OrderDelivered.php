<?php

declare(strict_types=1);

namespace Commerce\Ordering\Domain\Event;

final readonly class OrderDelivered extends OrderEvent
{
    protected function fact(): string
    {
        return 'delivered';
    }

    protected function details(): array
    {
        return [];
    }
}
