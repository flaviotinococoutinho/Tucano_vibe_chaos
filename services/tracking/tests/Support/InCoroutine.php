<?php

declare(strict_types=1);

namespace Tests\Support;

use ArrayObject;
use Swoole\Runtime;
use Throwable;

/** Runs code the way the server does: inside a coroutine, with the runtime hooks on. */
final class InCoroutine
{
    private function __construct() {}

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public static function run(callable $work): mixed
    {
        /** @var ArrayObject<string, mixed> $outcome */
        $outcome = new ArrayObject();
        $hooks = Runtime::getHookFlags();
        // An exception that escapes a coroutine is fatal for the whole process, so
        // it is carried out of the scheduler and thrown again here.
        \Swoole\Coroutine\run(static function () use ($work, $outcome): void {
            try {
                $outcome['result'] = $work();
            } catch (Throwable $error) {
                $outcome['error'] = $error;
            }
        });
        // run() turns every hook on and leaves them on; the other tests expect plain PHP.
        Runtime::setHookFlags($hooks);

        $error = $outcome['error'] ?? null;
        if ($error instanceof Throwable) {
            throw $error;
        }

        return $outcome['result'];
    }
}
