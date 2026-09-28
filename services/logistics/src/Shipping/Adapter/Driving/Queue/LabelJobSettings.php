<?php

declare(strict_types=1);

namespace Logistics\Shipping\Adapter\Driving\Queue;

/**
 * How a label job is tried: the tries, the waits between them, and the time one
 * try may take. They have to agree with the queue: as many tries as the
 * maxReceiveCount of its redrive policy, and a timeout below its visibility timeout.
 */
final readonly class LabelJobSettings
{
    /** @param list<int> $backoffSeconds */
    private function __construct(public int $tries, public array $backoffSeconds, public int $timeoutSeconds) {}

    /** @param list<int> $backoffSeconds seconds before each try after the first */
    public static function of(int $tries, array $backoffSeconds, int $timeoutSeconds): self
    {
        return new self($tries, $backoffSeconds, $timeoutSeconds);
    }
}
