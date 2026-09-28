<?php

declare(strict_types=1);

namespace Commerce\Shared\Application;

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
}
