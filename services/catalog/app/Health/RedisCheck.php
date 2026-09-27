<?php

declare(strict_types=1);

namespace App\Health;

use Illuminate\Redis\RedisManager;

final readonly class RedisCheck implements HealthCheck
{
    public function __construct(private RedisManager $redis) {}

    public function name(): string
    {
        return 'redis';
    }

    public function check(): void
    {
        $this->redis->connection()->command('ping');
    }
}
