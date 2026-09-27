<?php

declare(strict_types=1);

namespace Tucano\Messaging\Kafka;

use Closure;
use Throwable;

/**
 * Exponential backoff with full jitter: the wait is random between zero and
 * the exponential cap, so many consumers failing together do not retry in lockstep.
 */
final readonly class RetryPolicy
{
    private Closure $sleep;

    public function __construct(
        private int $maxAttempts = 5,
        private int $baseDelayMs = 200,
        private int $maxDelayMs = 5_000,
        ?Closure $sleep = null,
    ) {
        $this->sleep = $sleep ?? static function (int $milliseconds): void {
            usleep($milliseconds * 1_000);
        };
    }

    /**
     * @param Closure(): void          $work
     * @param Closure(Throwable): void $giveUp called once retries are exhausted or the failure is permanent
     */
    public function run(Closure $work, Closure $giveUp): void
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                $work();

                return;
            } catch (PermanentFailure $failure) {
                $giveUp($failure);

                return;
            } catch (Throwable $failure) {
                if ($attempt >= $this->maxAttempts) {
                    $giveUp($failure);

                    return;
                }
                ($this->sleep)($this->delayBefore($attempt + 1));
            }
        }
    }

    private function delayBefore(int $attempt): int
    {
        $cap = min($this->maxDelayMs, $this->baseDelayMs * 2 ** ($attempt - 2));

        return random_int(0, (int) $cap);
    }
}
