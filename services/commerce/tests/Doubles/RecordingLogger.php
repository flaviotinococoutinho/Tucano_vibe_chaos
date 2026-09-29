<?php

declare(strict_types=1);

namespace Tests\Doubles;

use Psr\Log\AbstractLogger;
use Stringable;

final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<mixed>}> */
    public private(set) array $records = [];

    /** @param array<mixed> $context */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
    }

    /** @return list<string> */
    public function messagesAt(string $level): array
    {
        return array_values(array_map(
            static fn(array $record): string => $record['message'],
            array_filter($this->records, static fn(array $record): bool => $record['level'] === $level),
        ));
    }

    /** @return list<array<mixed>> the context of every line logged with this message */
    public function contextsOf(string $message): array
    {
        return array_values(array_map(
            static fn(array $record): array => $record['context'],
            array_filter($this->records, static fn(array $record): bool => $record['message'] === $message),
        ));
    }
}
