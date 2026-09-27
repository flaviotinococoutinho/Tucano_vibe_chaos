<?php

declare(strict_types=1);

namespace Tucano\FeatureFlags\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tucano\FeatureFlags\FlagContext;

#[CoversClass(FlagContext::class)]
final class FlagContextTest extends TestCase
{
    #[Test]
    public function the_fingerprint_ignores_attribute_order(): void
    {
        $one = new FlagContext('customer-1', ['email' => 'ana@example.com', 'service' => 'commerce']);
        $other = new FlagContext('customer-1', ['service' => 'commerce', 'email' => 'ana@example.com']);

        self::assertSame($one->fingerprint(), $other->fingerprint());
    }

    #[Test]
    public function adding_an_attribute_returns_a_new_context(): void
    {
        $context = FlagContext::forCustomer('customer-1', 'ana@example.com');

        $withService = $context->with('service', 'commerce');

        self::assertSame(['email' => 'ana@example.com'], $context->attributes);
        self::assertSame(['email' => 'ana@example.com', 'service' => 'commerce'], $withService->attributes);
    }
}
