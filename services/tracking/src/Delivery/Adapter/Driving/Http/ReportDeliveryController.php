<?php

declare(strict_types=1);

namespace Tracking\Delivery\Adapter\Driving\Http;

use Closure;
use Tracking\Delivery\Adapter\DeliveryNewsJson;
use Tracking\Delivery\Application\Port\Driving\ForReportingDeliveries;
use Tracking\Platform\Http\HttpError;
use Tracking\Platform\Http\Request;
use Tracking\Platform\Http\Response;

/** POST /v1/positions: a courier's device reports a position or the end of a visit (contracts/tracking). */
final readonly class ReportDeliveryController
{
    public const string PATH = '/v1/positions';

    /** @param Closure(): int $now the clock, in Unix seconds */
    public function __construct(
        private ForReportingDeliveries $deliveries,
        private CourierSignature $signature,
        private Closure $now,
    ) {}

    public function __invoke(Request $request): Response
    {
        $verdict = $this->signature->verify($request->body, $request->header(CourierSignature::HEADER) ?? '', ($this->now)());
        if ($verdict !== SignatureVerdict::Valid) {
            throw HttpError::unauthorized(sprintf('The %s is %s.', CourierSignature::HEADER, $verdict->value));
        }

        $this->deliveries->report(DeliveryNewsJson::decode($request->body));

        return new Response(202);
    }
}
