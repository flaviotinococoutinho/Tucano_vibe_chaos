<?php

declare(strict_types=1);

namespace Tucano\SharedKernel\Domain;

use DateTimeImmutable;

interface DomainEvent
{
    /** UUIDv7 of the event itself; consumers de-duplicate on it. */
    public function eventId(): string;

    /** tucano.<context>.<aggregate>.<fact in the past>, e.g. tucano.commerce.order.paid */
    public function eventType(): string;

    public function aggregateId(): string;

    public function occurredAt(): DateTimeImmutable;

    /** @return array<string, mixed> */
    public function payload(): array;
}
