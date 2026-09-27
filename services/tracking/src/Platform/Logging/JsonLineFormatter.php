<?php

declare(strict_types=1);

namespace Tracking\Platform\Logging;

use Monolog\Formatter\JsonFormatter;
use Monolog\LogRecord;

/**
 * One flat JSON object per line: timestamp, level, message and service first,
 * then the extra fields (correlation_id) and the context. Flat keys keep log
 * queries short: correlation_id instead of extra.correlation_id.
 */
final class JsonLineFormatter extends JsonFormatter
{
    public function __construct()
    {
        parent::__construct(self::BATCH_MODE_NEWLINES, appendNewline: true, ignoreEmptyContextAndExtra: true, includeStacktraces: true);
        $this->setDateFormat('Y-m-d\TH:i:s.vp');
    }

    /** @return array<string, mixed> */
    protected function normalizeRecord(LogRecord $record): array
    {
        $normalized = parent::normalizeRecord($record);
        $line = [
            'timestamp' => $normalized['datetime'],
            'level' => $record->level->toPsrLogLevel(),
            'message' => $record->message,
            'service' => $record->channel,
        ];

        // The + operator never overwrites, so a context key cannot replace the fields above.
        return $line + self::fields($normalized['extra'] ?? []) + self::fields($normalized['context'] ?? []);
    }

    /** @return array<string, mixed> */
    private static function fields(mixed $normalized): array
    {
        return is_array($normalized) ? $normalized : [];
    }
}
