<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Fields that go into every log line. Laravel keeps them in its Context, which
 * Lumen does not have. They land under the same "extra" key, so log lines look
 * the same in every PHP service, and the events published during a request
 * read the correlation id from here too.
 */
final class LogContext implements ProcessorInterface
{
    public const string CORRELATION_ID = 'correlation_id';

    /** @var array<string, string> */
    private array $fields = [];

    public function add(string $key, string $value): void
    {
        $this->fields[$key] = $value;
    }

    public function get(string $key): ?string
    {
        return $this->fields[$key] ?? null;
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(extra: [...$record->extra, ...$this->fields]);
    }
}
