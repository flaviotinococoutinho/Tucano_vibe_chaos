<?php

declare(strict_types=1);

namespace Commerce\Inventory\Adapter\Driving\Http;

use Closure;
use Commerce\Inventory\Adapter\Driven\FlaggedStrategies;
use Commerce\Shared\Adapter\Driving\Http\Preferences;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Chooses the reservation strategy once, before a request that reserves stock starts its
 * transaction. When the lab follows the preference of the request, the answer says so in
 * Preference-Applied (RFC 7240), so an experiment knows which strategy really ran.
 */
final readonly class ChooseReservationStrategy
{
    public function __construct(private FlaggedStrategies $strategies) {}

    public function handle(Request $request, Closure $next): Response
    {
        $applied = $this->strategies->chooseFor(Preferences::of($request)->valueOf(FlaggedStrategies::PREFERENCE));

        $response = $next($request);
        if ($applied !== null) {
            $response->headers->set('Preference-Applied', FlaggedStrategies::PREFERENCE . '=' . $applied->value);
        }

        return $response;
    }
}
