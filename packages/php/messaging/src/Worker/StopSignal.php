<?php

declare(strict_types=1);

namespace Tucano\Messaging\Worker;

/**
 * Long-running workers check this between messages, so SIGTERM (docker stop,
 * a Kubernetes rollout) finishes the current message instead of killing it midway.
 */
final class StopSignal
{
    private WorkerState $state = WorkerState::Running;

    public static function onTermination(): self
    {
        $signal = new self();
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, static fn() => $signal->stop());
            pcntl_signal(SIGINT, static fn() => $signal->stop());
        }

        return $signal;
    }

    public function stop(): void
    {
        $this->state = WorkerState::Stopping;
    }

    public function requested(): bool
    {
        return $this->state === WorkerState::Stopping;
    }
}
