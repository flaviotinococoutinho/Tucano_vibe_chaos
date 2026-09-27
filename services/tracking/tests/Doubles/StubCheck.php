<?php

declare(strict_types=1);

namespace Tests\Doubles;

use RuntimeException;
use Tracking\Platform\Health\HealthCheck;

final readonly class StubCheck implements HealthCheck
{
    private function __construct(private string $name, private ?string $failure) {}

    public static function up(string $name): self
    {
        return new self($name, null);
    }

    public static function down(string $name, string $failure): self
    {
        return new self($name, $failure);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function check(): void
    {
        if ($this->failure !== null) {
            throw new RuntimeException($this->failure);
        }
    }
}
