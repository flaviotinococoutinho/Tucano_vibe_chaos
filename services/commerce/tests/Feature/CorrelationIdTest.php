<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CorrelationIdTest extends TestCase
{
    #[Test]
    public function it_keeps_the_id_that_came_from_the_gateway(): void
    {
        $this->getJson('/health/live', ['X-Correlation-Id' => '4f1c2b7e-9d7a-4b8c-9e3f-1a2b3c4d5e6f#12'])
            ->assertHeader('X-Correlation-Id', '4f1c2b7e-9d7a-4b8c-9e3f-1a2b3c4d5e6f#12');
    }

    #[Test]
    public function it_creates_one_when_the_request_has_none(): void
    {
        $header = (string) $this->getJson('/health/live')->headers->get('X-Correlation-Id');

        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-/', $header);
    }
}
