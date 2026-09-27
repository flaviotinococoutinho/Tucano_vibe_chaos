<?php

declare(strict_types=1);

namespace Tucano\Messaging\Kafka;

use Closure;
use Throwable;
use Tucano\Messaging\Worker\StopSignal;

/**
 * Exponential backoff with full jitter: the wait is random between zero and
 * the exponential cap, so many consumers failing together do not retry in lockstep.
 *
 * Each kind of failure gets its own answer. A permanent one gives up at once,
 * since it would fail the same way again. A lost database connection retries
 * until the connection is back: giving up would send a good message to the dead
 * letter topic, and every message after it would fail the same way. Anything
 * else gets a bounded number of attempts. A stop cuts the wait short, and the
 * message is neither handled nor given up: it comes back after the restart.
 */
final readonly class RetryPolicy
{
    private Closure $sleep;

    private Closure $unlimited;

    /**
     * @param (Closure(int, StopSignal): void)|null $sleep     waits the given milliseconds, cut short by a stop
     * @param (Closure(Throwable): bool)|null       $unlimited the failures that retry without a limit
     */
    public function __construct(
        private int $maxAttempts = 5,
        private int $baseDelayMs = 200,
        private int $maxDelayMs = 5_000,
        ?Closure $sleep = null,
        ?Closure $unlimited = null,
    ) {
        $this->sleep = $sleep ?? static function (int $milliseconds, StopSignal $stop): void {
            $stop->pause($milliseconds);
        };
        $this->unlimited = $unlimited ?? LostConnection::causedBy(...);
    }

    /**
     * @param Closure(): void                     $work
     * @param Closure(Throwable): void            $giveUp   called once the failure is permanent or the attempts ran out
     * @param (Closure(Throwable, int): void)|null $retrying called before each wait, with the failure and the wait in milliseconds
     */
    public function run(Closure $work, Closure $giveUp, StopSignal $stop, ?Closure $retrying = null): RetryOutcome
    {
        $counted = 0;
        for ($attempt = 1; ; $attempt++) {
            try {
                $work();

                return RetryOutcome::Succeeded;
            } catch (PermanentFailure $failure) {
                $giveUp($failure);

                return RetryOutcome::GaveUp;
            } catch (Throwable $failure) {
                if (!($this->unlimited)($failure) && ++$counted >= $this->maxAttempts) {
                    $giveUp($failure);

                    return RetryOutcome::GaveUp;
                }
                $wait = $this->delayBefore($attempt + 1);
                if ($retrying !== null) {
                    $retrying($failure, $wait);
                }
                ($this->sleep)($wait, $stop);
                if ($stop->requested()) {
                    return RetryOutcome::Interrupted;
                }
            }
        }
    }

    private function delayBefore(int $attempt): int
    {
        $cap = min($this->maxDelayMs, $this->baseDelayMs * 2 ** min($attempt - 2, 30));

        return random_int(0, (int) $cap);
    }
}
