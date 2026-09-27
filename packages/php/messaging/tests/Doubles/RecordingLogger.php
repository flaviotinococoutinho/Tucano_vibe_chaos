<?php

declare(strict_types=1);

namespace Tucano\Messaging\Tests\Doubles;

use Psr\Log\AbstractLogger;
use Stringable;

final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<mixed>}> */
    public array $records = [];

    /** @param array<mixed> $context */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => is_string($level) ? $level : 'unknown', 'message' => (string) $message, 'context' => $context];
    }
}
