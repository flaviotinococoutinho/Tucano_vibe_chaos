<?php

declare(strict_types=1);

namespace Tucano\Messaging\Tests\Worker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tucano\Messaging\Worker\StopSignal;

#[CoversClass(StopSignal::class)]
final class StopSignalTest extends TestCase
{
    #[Test]
    public function a_new_worker_keeps_running(): void
    {
        self::assertFalse((new StopSignal())->requested());
    }

    #[Test]
    public function a_pause_lasts_as_long_as_asked(): void
    {
        $started = hrtime(true);

        (new StopSignal())->pause(50);

        self::assertGreaterThanOrEqual(50, (hrtime(true) - $started) / 1e6);
    }

    #[Test]
    public function a_stopping_worker_does_not_pause(): void
    {
        $signal = new StopSignal();
        $signal->stop();
        $started = hrtime(true);

        $signal->pause(5_000);

        self::assertLessThan(100, (hrtime(true) - $started) / 1e6);
    }

    #[Test]
    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function sigterm_asks_the_worker_to_stop(): void
    {
        $signal = StopSignal::onTermination();

        posix_kill(posix_getpid(), SIGTERM);

        self::assertTrue($signal->requested());
        pcntl_signal(SIGTERM, SIG_DFL);
    }
}
