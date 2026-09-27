<?php

declare(strict_types=1);

namespace Logistics\Shared\Application;

/** Transaction isolation levels the use cases ask for. */
enum Isolation
{
    case ReadCommitted;
    case Serializable;

    public function sql(): string
    {
        return match ($this) {
            self::ReadCommitted => 'READ COMMITTED',
            self::Serializable => 'SERIALIZABLE',
        };
    }

    /**
     * PostgreSQL may refuse a serializable transaction (SQLSTATE 40001) instead
     * of letting it see an anomaly. The fix is to run it again from the start.
     */
    public function attempts(): int
    {
        return match ($this) {
            self::ReadCommitted => 1,
            self::Serializable => 5,
        };
    }
}
