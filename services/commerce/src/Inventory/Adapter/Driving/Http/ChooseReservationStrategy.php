<?php

declare(strict_types=1);

namespace Commerce\Inventory\Adapter\Driving\Http;

use Closure;
use Commerce\Inventory\Adapter\Driven\FlaggedStrategies;
use Commerce\Inventory\Application\ReservationStrategy;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use ValueError;

/** Chooses the reservation strategy once, before a request that reserves stock starts its transaction. */
final readonly class ChooseReservationStrategy
{
    public function __construct(private FlaggedStrategies $strategies) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $this->strategies->chooseFor($request->header(FlaggedStrategies::LAB_HEADER));
        } catch (ValueError) {
            throw new BadRequestHttpException(sprintf(
                '%s must be one of %s.',
                FlaggedStrategies::LAB_HEADER,
                implode(', ', array_map(static fn(ReservationStrategy $strategy): string => $strategy->value, ReservationStrategy::cases())),
            ));
        }

        return $next($request);
    }
}
