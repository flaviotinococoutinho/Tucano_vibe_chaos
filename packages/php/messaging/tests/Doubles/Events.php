<?php

declare(strict_types=1);

namespace Tucano\Messaging\Tests\Doubles;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use Tucano\SharedKernel\Messaging\CloudEvent;

final class Events
{
    public static function orderPaid(string $orderId = '01926f39-1d2c-7a8b-8c9d-0e1f2a3b4c5d'): CloudEvent
    {
        return new CloudEvent(
            Uuid::uuid7()->toString(),
            '/commerce',
            'tucano.commerce.order.paid',
            $orderId,
            new DateTimeImmutable('2026-09-27T12:00:04.501Z'),
            'req-1#1',
            null,
            ['orderId' => $orderId],
        );
    }
}
