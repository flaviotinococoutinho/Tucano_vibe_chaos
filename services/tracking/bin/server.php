<?php

declare(strict_types=1);

use Monolog\ErrorHandler;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Swoole\WebSocket\Server;
use Tracking\Platform\CompositionRoot;
use Tracking\Platform\Config;
use Tracking\Platform\Http\Kernel;
use Tracking\Platform\Http\SwooleBridge;

require __DIR__ . '/../vendor/autoload.php';

$config = Config::fromEnvironment(getenv());
$logger = CompositionRoot::logger($config);

// PHP warnings and fatal errors reach the log as JSON lines too, instead of plain text.
$errors = new ErrorHandler($logger);
$errors->registerErrorHandler(callPrevious: false);
$errors->registerFatalHandler();

// BASE mode: each worker owns the connections it accepts, so on SIGTERM it
// finishes its requests and delivers the responses before exiting. In PROCESS
// mode the master closes every connection at once. WebSocket fan-out between
// workers goes through Redis pub/sub, as it has to between instances anyway.
$server = new Server('0.0.0.0', 9501, SWOOLE_BASE);
$server->set([
    'worker_num' => $config->workers,
    'daemonize' => false,
    // Sockets, phpredis, curl, sleep and file calls yield to other coroutines instead of blocking the worker.
    'hook_flags' => SWOOLE_HOOK_ALL,
    // On SIGTERM, requests in flight get this many seconds to finish, within Docker's 10 second stop timeout.
    'max_wait_time' => 5,
    // Swoole's own log lines are plain text, so they are kept for warnings and errors.
    'log_level' => SWOOLE_LOG_WARNING,
]);

/** @var Kernel|null $kernel set in workerStart, before the worker takes any request */
$kernel = null;

$server->on('start', static function (Server $server) use ($config, $logger): void {
    $logger->info('Tracking server started', ['port' => $server->port, 'workers' => $config->workers, 'swoole' => SWOOLE_VERSION]);
});

$server->on('workerStart', static function (Server $server, int $workerId) use ($config, $logger, &$kernel): void {
    $kernel = CompositionRoot::boot($config, $logger)->kernel;
    $logger->info('Worker started', ['worker' => $workerId]);
});

$server->on('workerStop', static function (Server $server, int $workerId) use ($logger): void {
    $logger->info('Worker stopped', ['worker' => $workerId]);
});

$server->on('shutdown', static function () use ($logger): void {
    $logger->info('Tracking server stopped');
});

$server->on('request', static function (SwooleRequest $request, SwooleResponse $response) use (&$kernel): void {
    assert($kernel instanceof Kernel);
    SwooleBridge::send($kernel->handle(SwooleBridge::request($request)), $response);
});

// Upgrade requests go through the same router as plain HTTP. No route accepts an
// upgrade yet, so a WebSocket client gets the same 404 problem as any unknown path.
$server->on('handshake', static function (SwooleRequest $request, SwooleResponse $response) use (&$kernel): bool {
    assert($kernel instanceof Kernel);
    SwooleBridge::send($kernel->handle(SwooleBridge::request($request)), $response);

    return false;
});

// Swoole refuses to start a WebSocket server without this callback. Every
// handshake is turned down above, so no frame can reach it.
$server->on('message', static function (): void {});

$server->start();
