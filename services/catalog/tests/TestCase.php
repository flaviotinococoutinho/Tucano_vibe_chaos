<?php

declare(strict_types=1);

namespace Tests;

use Laravel\Lumen\Application;
use Laravel\Lumen\Testing\TestCase as LumenTestCase;

abstract class TestCase extends LumenTestCase
{
    public function createApplication(): Application
    {
        return require __DIR__ . '/../bootstrap/app.php';
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // Every Lumen application installs an error and an exception handler, and
        // PHPUnit flags a test that leaves handlers behind as risky.
        restore_error_handler();
        restore_exception_handler();
    }
}
