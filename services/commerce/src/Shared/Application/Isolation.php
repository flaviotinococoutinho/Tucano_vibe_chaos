<?php

declare(strict_types=1);

namespace Commerce\Shared\Application;

/** Transaction isolation levels the use cases ask for. */
enum Isolation
{
    case ReadCommitted;
    /** One snapshot for the whole transaction: reads that must agree with each other, like an order and its history. */
    case RepeatableRead;
    case Serializable;

    public function sql(): string
    {
        return match ($this) {
            self::ReadCommitted => 'READ COMMITTED',
            self::RepeatableRead => 'REPEATABLE READ',
            self::Serializable => 'SERIALIZABLE',
        };
    }
}
