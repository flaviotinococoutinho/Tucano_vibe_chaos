<?php

declare(strict_types=1);

namespace Tests;

use Laravel\Lumen\Application;
use Laravel\Lumen\Testing\TestCase as LumenTestCase;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Tucano\Messaging\Kafka\InMemoryProducer;
use Tucano\Messaging\Kafka\Producer;

abstract class TestCase extends LumenTestCase
{
    /** Tests never talk to Kafka: what the code publishes lands here. */
    protected InMemoryProducer $kafka;

    public function createApplication(): Application
    {
        /** @var Application $app */
        $app = require __DIR__ . '/../bootstrap/app.php';
        $this->kafka = new InMemoryProducer();
        $app->instance(Producer::class, $this->kafka);

        return $app;
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // Every Lumen application installs an error and an exception handler, and
        // PHPUnit flags a test that leaves handlers behind as risky.
        restore_error_handler();
        restore_exception_handler();
    }

    /** Keeps every log line in memory, from any level, for the test to inspect. */
    protected function captureLogs(): TestHandler
    {
        $logs = new TestHandler();
        $this->app->instance(LoggerInterface::class, new Logger('catalog', [$logs]));

        return $logs;
    }
}
