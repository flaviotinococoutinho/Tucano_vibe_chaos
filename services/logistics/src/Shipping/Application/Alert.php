<?php

declare(strict_types=1);

namespace Logistics\Shipping\Application;

/**
 * Something a person of the operation has to look at: a subject and the lines
 * that explain it. The fingerprint says when two alerts are the same news, so
 * the same stalled shipments do not fill the inbox round after round.
 */
final readonly class Alert
{
    /** @param list<string> $lines */
    private function __construct(public string $fingerprint, public string $subject, public array $lines) {}

    public static function of(string $fingerprint, string $subject, string ...$lines): self
    {
        return new self($fingerprint, $subject, array_values($lines));
    }

    public function body(): string
    {
        return implode("\n", $this->lines);
    }
}
