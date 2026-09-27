<?php

declare(strict_types=1);

namespace Logistics\Shipping\Domain\Guard;

use Logistics\Shipping\Domain\Error\TransitionRefused;
use Logistics\Shipping\Domain\Transition\TransitionRequest;

/**
 * One link of the guard chain (Chain of Responsibility). Each guard checks its
 * own rule and hands the request to the next link; the first one that refuses
 * throws, and the transition does not happen.
 */
abstract readonly class TransitionGuard
{
    public function __construct(private ?TransitionGuard $next = null) {}

    /** @throws TransitionRefused */
    final public function check(TransitionRequest $request): void
    {
        $this->verify($request);
        $this->next?->check($request);
    }

    /** @throws TransitionRefused */
    abstract protected function verify(TransitionRequest $request): void;
}
