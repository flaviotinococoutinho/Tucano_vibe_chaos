<?php

declare(strict_types=1);

namespace Commerce\Inventory\Adapter\Driven;

use Commerce\Inventory\Application\Port\Driven\ForChoosingStrategy;
use Commerce\Inventory\Application\ReservationStrategy;
use Illuminate\Support\Facades\Context;
use Psr\Log\LoggerInterface;
use Tucano\FeatureFlags\FeatureFlags;

/**
 * The strategy comes from the flag inventory.reservation-strategy. In the lab, the
 * preference `Prefer: reservation-strategy=<name>` (RFC 7240) overrides it for one
 * request, and only while labs.enabled is on (the ProductionGuard keeps labs.* off in
 * production). A preference is a hint: out of the lab, or naming a strategy that does
 * not exist, it is ignored, and the answer does not say Preference-Applied.
 * The choice goes into the request Context: the holds and the isolation of the
 * transaction read the same answer, and every log line of the request shows it.
 */
final readonly class FlaggedStrategies implements ForChoosingStrategy
{
    public const string PREFERENCE = 'reservation-strategy';

    private const string CONTEXT_KEY = 'inventory_strategy';

    private const string FLAG = 'inventory.reservation-strategy';

    public function __construct(private FeatureFlags $flags, private LoggerInterface $logger) {}

    public function current(): ReservationStrategy
    {
        $chosen = Context::get(self::CONTEXT_KEY);

        return (is_string($chosen) ? ReservationStrategy::tryFrom($chosen) : null) ?? $this->fromFlag();
    }

    /**
     * Chooses the strategy of this request and returns the preference when the lab applied
     * it, or null when the flag chose.
     */
    public function chooseFor(?string $preferred): ?ReservationStrategy
    {
        $fromLab = $this->fromLab($preferred);
        Context::add(self::CONTEXT_KEY, ($fromLab ?? $this->fromFlag())->value);

        return $fromLab;
    }

    private function fromLab(?string $preferred): ?ReservationStrategy
    {
        if ($preferred === null || !$this->flags->enabled('labs.enabled')) {
            return null;
        }

        return ReservationStrategy::tryFrom(strtolower($preferred));
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
