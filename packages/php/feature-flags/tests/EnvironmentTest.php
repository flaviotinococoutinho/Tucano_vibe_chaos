<?php

declare(strict_types=1);

namespace Tucano\FeatureFlags\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tucano\FeatureFlags\Environment;

#[CoversClass(Environment::class)]
final class EnvironmentTest extends TestCase
{
    #[Test]
    public function it_reads_the_app_env(): void
    {
        self::assertSame(Environment::Staging, Environment::fromName(' Staging '));
        self::assertSame(Environment::Local, Environment::fromName('local'));
    }

    #[Test]
    public function anything_unknown_is_treated_as_production(): void
    {
        self::assertSame(Environment::Production, Environment::fromName('testing'));
        self::assertSame(Environment::Production, Environment::fromName(null));
    }
}
