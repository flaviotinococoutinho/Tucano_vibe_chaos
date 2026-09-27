<?php

declare(strict_types=1);

namespace Tracking\Platform\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Tracking\Platform\RequestContext;

/** Adds the correlation id of the request being handled, so every line of that request carries it. */
final readonly class CorrelationIdProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $correlationId = RequestContext::correlationId();
        if ($correlationId !== null) {
            $record->extra['correlation_id'] = $correlationId;
        }

        return $record;
    }
}
