<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Coroutine\WaitGroup;
use Tests\Support\InCoroutine;
use Tracking\Platform\Health\Readiness;
use Tracking\Platform\Health\RedisCheck;

final class RedisCheckTest extends TestCase
{
    #[Test]
    #[Group('integration')]
    public function redis_is_up_when_it_answers(): void
    {
        $report = InCoroutine::run(static fn(): array => (new Readiness([self::redis()]))->probe()->toArray());

        self::assertSame('up', $report['checks']['redis']['status'], $report['checks']['redis']['error'] ?? '');
    }

    #[Test]
    #[Group('integration')]
    public function coroutines_can_probe_at_the_same_time(): void
    {
        // A phpredis client shared by these coroutines would fail with "socket already bound to another coroutine".
        $check = self::redis();
        $errors = InCoroutine::run(static function () use ($check): array {
            $errors = [];
            $probes = new WaitGroup();
            for ($probe = 0; $probe < 20; ++$probe) {
                $probes->add();
                Coroutine::create(static function () use ($check, $probes, &$errors): void {
                    $report = (new Readiness([$check]))->probe()->toArray();
                    if ($report['status'] === 'down') {
                        $errors[] = $report['checks']['redis']['error'] ?? 'down';
                    }
                    $probes->done();
                });
            }
            $probes->wait();

            return $errors;
        });

        self::assertSame([], $errors);
    }

    #[Test]
    public function a_refused_connection_is_reported_as_down(): void
    {
        $check = new RedisCheck('127.0.0.1', 1, 0.5);

        $report = InCoroutine::run(static fn(): array => (new Readiness([$check]))->probe()->toArray());

        self::assertSame('down', $report['status']);
        self::assertNotEmpty($report['checks']['redis']['error'] ?? '');
    }

    private static function redis(): RedisCheck
    {
        return new RedisCheck((string) getenv('REDIS_HOST'), (int) getenv('REDIS_PORT'));
    }
}
