<?php

declare(strict_types=1);

use App\Console\Kernel;
use App\Exceptions\Handler;
use App\Http\Middleware\CorrelationId;
use App\Providers\PlatformServiceProvider;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Redis\RedisServiceProvider;
use Laravel\Lumen\Application;
use Laravel\Lumen\Routing\Router;

require_once __DIR__ . '/../vendor/autoload.php';

// Dates and log timestamps in UTC, whatever the php.ini says.
date_default_timezone_set('UTC');

// Facades and Eloquent stay off, as Lumen ships them: dependencies arrive through constructors.
$app = new Application(dirname(__DIR__));

$app->singleton(ExceptionHandler::class, Handler::class);
$app->singleton(ConsoleKernel::class, Kernel::class);

// Lumen loads a config file only when a component first asks for it. Loading
// all of them here means Redis finds its connections and tests can override any value.
$app->configure('app');
$app->configure('cache');
$app->configure('database');
$app->configure('logging');
$app->configure('platform');

$app->middleware([CorrelationId::class]);

$app->register(RedisServiceProvider::class);
$app->register(PlatformServiceProvider::class);

$app->router->group(['namespace' => 'App\Http\Controllers'], static function (Router $router): void {
    require __DIR__ . '/../routes/web.php';
});

return $app;
