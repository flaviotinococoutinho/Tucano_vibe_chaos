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
