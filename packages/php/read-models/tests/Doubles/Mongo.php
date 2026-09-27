<?php

declare(strict_types=1);

namespace Tucano\ReadModels\Tests\Doubles;

use MongoDB\Client;
use MongoDB\Database;
use PHPUnit\Framework\TestCase;

final class Mongo
{
    public static function freshDatabase(): Database
    {
        $uri = (string) getenv('READ_MODELS_MONGO_URI');
        if ($uri === '') {
            TestCase::markTestSkipped('Set READ_MODELS_MONGO_URI to run the MongoDB tests.');
        }

        return (new Client($uri))->selectDatabase('read_models_tests_' . bin2hex(random_bytes(4)));
    }
}
