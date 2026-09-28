<?php

declare(strict_types=1);

namespace Logistics\CarrierSelection\Adapter\Driven;

use Logistics\CarrierSelection\Application\Port\Driven\ForChoosingDispatchMode;
use Logistics\CarrierSelection\Domain\DispatchMode;
use Tucano\FeatureFlags\FeatureFlags;

/**
 * The dispatch mode is an ops toggle in flagd. The use case never sees the
 * flag: swapping flagd for a table, an admin screen or another vendor changes
 * this class and nothing else. flagd away means the fallback, partners only.
 */
final readonly class FlaggedDispatchMode implements ForChoosingDispatchMode
{
    private const string OWN_FLEET_DISPATCH = 'logistics.own-fleet-dispatch';

    public function __construct(private FeatureFlags $flags) {}

    public function current(): DispatchMode
    {
        return $this->flags->enabled(self::OWN_FLEET_DISPATCH) ? DispatchMode::OwnFleetFirst : DispatchMode::PartnersOnly;
    }
}
