<?php

declare(strict_types=1);

namespace Tucano\FeatureFlags\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tucano\FeatureFlags\Cache\InMemoryFlagCache;
use Tucano\FeatureFlags\CachedFlags;
use Tucano\FeatureFlags\FlagContext;
use Tucano\FeatureFlags\InMemoryFlags;
use Tucano\SharedKernel\Time\FrozenClock;

#[CoversClass(CachedFlags::class)]
#[CoversClass(InMemoryFlagCache::class)]
final class CachedFlagsTest extends TestCase
{
    #[Test]
    public function a_hot_flag_is_evaluated_once_per_ttl(): void
    {
        $server = new InMemoryFlags(['inventory.reservation-strategy' => 'atomic']);
        $flags = new CachedFlags($server, new InMemoryFlagCache(new FrozenClock()), ttlSeconds: 2);

        $flags->text('inventory.reservation-strategy', 'atomic');
        $flags->text('inventory.reservation-strategy', 'atomic');
        $flags->text('inventory.reservation-strategy', 'atomic');

        self::assertSame(1, $server->readsOf('inventory.reservation-strategy'));
    }

    #[Test]
    public function changes_show_up_once_the_ttl_expires(): void
    {
        $clock = new FrozenClock();
        $server = new InMemoryFlags(['chaos.commerce.outbox-relay-paused' => false]);
        $flags = new CachedFlags($server, new InMemoryFlagCache($clock), ttlSeconds: 2);
        $flags->enabled('chaos.commerce.outbox-relay-paused');

        $server->set('chaos.commerce.outbox-relay-paused', true);
        self::assertFalse($flags->enabled('chaos.commerce.outbox-relay-paused'));

        $clock->advance('+2 seconds');
        self::assertTrue($flags->enabled('chaos.commerce.outbox-relay-paused'));
    }

    #[Test]
    public function each_customer_gets_its_own_evaluation(): void
    {
        $server = new InMemoryFlags(['checkout.express-shipping' => true]);
        $flags = new CachedFlags($server, new InMemoryFlagCache(new FrozenClock()));

        $flags->enabled('checkout.express-shipping', false, FlagContext::forCustomer('customer-1'));
        $flags->enabled('checkout.express-shipping', false, FlagContext::forCustomer('customer-2'));

        self::assertSame(2, $server->readsOf('checkout.express-shipping'));
    }
}
