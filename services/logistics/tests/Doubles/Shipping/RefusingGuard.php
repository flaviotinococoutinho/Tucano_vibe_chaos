<?php

declare(strict_types=1);

namespace Tests\Doubles\Shipping;

use Logistics\Shipping\Domain\Error\TransitionRefused;
use Logistics\Shipping\Domain\Guard\TransitionGuard;
use Logistics\Shipping\Domain\Transition\TransitionRequest;

/** A link that refuses every request. */
final readonly class RefusingGuard extends TransitionGuard
{
    protected function verify(TransitionRequest $request): void
    {
        throw TransitionRefused::withoutHub();
    }
}
