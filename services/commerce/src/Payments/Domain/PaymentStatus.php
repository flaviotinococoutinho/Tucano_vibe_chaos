<?php

declare(strict_types=1);

namespace Commerce\Payments\Domain;

/** The payment state machine (docs/architecture/state-machines.md), one exhaustive table. */
enum PaymentStatus: string
{
    case Pending = 'pending';
    /** Tucano gave up waiting: no charge at the provider, and the order stopped waiting too. */
    case Abandoned = 'abandoned';
    case Captured = 'captured';
    case Failed = 'failed';
    case RefundRequested = 'refund_requested';
    case Refunded = 'refunded';

    /** @return list<self> */
    public function next(): array
    {
        return match ($this) {
            self::Pending => [self::Captured, self::Failed, self::Abandoned],
            // Giving up was a guess; when the provider speaks after all, its word wins.
            self::Abandoned => [self::RefundRequested, self::Failed],
            self::Captured => [self::RefundRequested],
            self::RefundRequested => [self::Refunded],
            self::Failed, self::Refunded => [],
        };
    }

    public function canMoveTo(self $target): bool
    {
        return in_array($target, $this->next(), true);
    }
}
