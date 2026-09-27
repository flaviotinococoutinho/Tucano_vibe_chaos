<?php

declare(strict_types=1);

namespace Logistics\CarrierSelection\Application;

final readonly class ChosenCarrier
{
    public function __construct(public string $code) {}
}
