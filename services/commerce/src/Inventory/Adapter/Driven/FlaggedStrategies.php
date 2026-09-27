<?php

declare(strict_types=1);

namespace Commerce\Inventory\Adapter\Driven;

use Commerce\Inventory\Application\Port\Driven\ForChoosingStrategy;
use Commerce\Inventory\Application\ReservationStrategy;
use Illuminate\Support\Facades\Context;
use Psr\Log\LoggerInterface;
use Tucano\FeatureFlags\FeatureFlags;

/**
 * The strategy comes from the flag inventory.reservation-strategy. In the lab,
 * the X-Inventory-Strategy header overrides it for one request, and only while
 * labs.enabled is on (the ProductionGuard keeps labs.* off in production).
 * The choice goes into the request Context: the holds and the isolation of the
 * transaction read the same answer, and every log line of the request shows it.
 */
final readonly class FlaggedStrategies implements ForChoosingStrategy
{
    public const string LAB_HEADER = 'X-Inventory-Strategy';

    private const string CONTEXT_KEY = 'inventory_strategy';

    private const string FLAG = 'inventory.reservation-strategy';

    public function __construct(private FeatureFlags $flags, private LoggerInterface $logger) {}

    public function current(): ReservationStrategy
    {
        $chosen = Context::get(self::CONTEXT_KEY);

        return (is_string($chosen) ? ReservationStrategy::tryFrom($chosen) : null) ?? $this->fromFlag();
    }

    /** @throws \ValueError when the lab header names a strategy that does not exist */
    public function chooseFor(?string $labHeader): ReservationStrategy
    {
        $strategy = $this->fromLab($labHeader) ?? $this->fromFlag();
        Context::add(self::CONTEXT_KEY, $strategy->value);

        return $strategy;
    }

    private function fromLab(?string $header): ?ReservationStrategy
    {
        if ($header === null || $header === '' || !$this->flags->enabled('labs.enabled')) {
            return null;
        }

        return ReservationStrategy::from(strtolower($header));
    }

    private function fromFlag(): ReservationStrategy
    {
        $value = $this->flags->text(self::FLAG, ReservationStrategy::Atomic->value);
        $strategy = ReservationStrategy::tryFrom($value);
        if ($strategy === null) {
            $this->logger->warning('Unknown reservation strategy {strategy} in the flags, using atomic', ['strategy' => $value]);
        }

        return $strategy ?? ReservationStrategy::Atomic;
    }
}
