<?php

declare(strict_types=1);

namespace Tests\Doubles\Shipping;

use ArrayObject;
use Logistics\Shipping\Domain\Guard\TransitionGuard;
use Logistics\Shipping\Domain\Transition\TransitionRequest;

/** A link that lets everything through and writes down that the request passed by. */
final readonly class RecordingGuard extends TransitionGuard
{
    /** @param ArrayObject<int, string> $visits */
    public function __construct(private string $name, private ArrayObject $visits, ?TransitionGuard $next = null)
    {
        parent::__construct($next);
    }

    protected function verify(TransitionRequest $request): void
    {
        $this->visits->append($this->name);
    }
}
