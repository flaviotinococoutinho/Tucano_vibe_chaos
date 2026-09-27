<?php

declare(strict_types=1);

namespace App\Health;

final readonly class ReadinessReport
{
    /** @param array<string, array{status: string, latencyMs: int, error?: string}> $checks */
    public function __construct(private array $checks = []) {}

    public function with(string $name, int $latencyMs, ?string $error): self
    {
        $result = ['status' => $error === null ? 'up' : 'down', 'latencyMs' => $latencyMs];
        if ($error !== null) {
            $result['error'] = $error;
        }

        return new self([...$this->checks, $name => $result]);
    }

    public function isHealthy(): bool
    {
        $down = array_filter($this->checks, static fn(array $check): bool => $check['status'] === 'down');

        return $down === [];
    }

    /** @return array{status: string, checks: array<string, array{status: string, latencyMs: int, error?: string}>} */
    public function toArray(): array
    {
        return ['status' => $this->isHealthy() ? 'up' : 'down', 'checks' => $this->checks];
    }
}
