<?php

declare(strict_types=1);

use Monolog\ErrorHandler;
use Swoole\Coroutine;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Swoole\WebSocket\Server;
use Tracking\Delivery\Adapter\Driving\Http\FollowLiveController;
use Tracking\Delivery\Adapter\Driving\WebSocket\DeliveriesSubscription;
use Tracking\Delivery\Adapter\Driving\WebSocket\LiveFollowers;
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
$server = new Server($config->host, $config->port, SWOOLE_BASE);
$server->set([
    'worker_num' => $config->workers,
    'daemonize' => false,
    // Sockets, phpredis, curl, sleep and file calls yield to other coroutines instead of blocking the worker.
    'hook_flags' => SWOOLE_HOOK_ALL,
    // On SIGTERM, requests in flight get this many seconds to finish, within Docker's 10 second stop timeout.
    'max_wait_time' => $config->maxWaitSeconds,
    // Swoole's own log lines are plain text, so they are kept for warnings and errors.
    'log_level' => SWOOLE_LOG_WARNING,
    // A worker on its way out gets workerExit calls, where it lets its followers go and stops listening.
    'reload_async' => true,
]);

/** @var Kernel|null $kernel set in workerStart, before the worker takes any request */
$kernel = null;
/** @var LiveFollowers|null $live the WebSocket connections of this worker, by the tracking code they follow */
$live = null;
/** @var DeliveriesSubscription|null $subscription the coroutine that listens to the deliveries channel */
$subscription = null;
$subscriberId = 0;

$server->on('start', static function (Server $server) use ($config, $logger): void {
    $logger->info('Tracking server started', ['port' => $server->port, 'workers' => $config->workers, 'swoole' => SWOOLE_VERSION]);
});

$server->on('workerStart', static function (Server $server, int $workerId) use ($config, $logger, &$kernel, &$live, &$subscription, &$subscriberId): void {
    $root = CompositionRoot::boot($config, $logger);
    $kernel = $root->kernel;
    $live = new LiveFollowers(
        static fn(int $connection, string $frame): bool => $server->isEstablished($connection) && $server->push($connection, $frame),
        static fn(int $connection, int $code, string $reason): bool => $server->isEstablished($connection) && $server->disconnect($connection, $code, $reason),
        $root->deliveries,
        $logger,
    );
    $subscription = $root->subscription;
    $followers = $live;
    $listener = $subscription;
    $subscriberId = (int) Coroutine::create(static fn() => $listener->run($followers));
    $logger->info('Worker started', ['worker' => $workerId]);
});

// While it waits for its last events on the way out, the worker lets its followers go (1001),
// so they connect again elsewhere, and stops listening, or the pending read would keep it alive.
$server->on('workerExit', static function () use (&$live, &$subscription, &$subscriberId): void {
    $subscription?->stop();
    if ($subscriberId > 0) {
        Coroutine::cancel($subscriberId);
        $subscriberId = 0;
    }
    $live?->letEveryoneGo();
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

// Upgrade requests go through the same router as plain HTTP, so a refusal is the same problem
// details with the correlation id. Only the live route answers 101, and only then does the
// connection become a WebSocket that follows a tracking code (UC-TRK-03).
$server->on('handshake', static function (SwooleRequest $request, SwooleResponse $response) use (&$kernel, &$live): bool {
    assert($kernel instanceof Kernel && $live instanceof LiveFollowers);
    $plain = SwooleBridge::request($request);
    $answer = $kernel->handle($plain);
    SwooleBridge::send($answer, $response);
    if ($answer->status !== 101) {
        return false;
    }

    $connection = $request->fd;
    $followers = $live;
    $followers->follow($connection, FollowLiveController::trackingCodeOf($plain));
    // The handshake completes when this callback returns; the welcome waits on Redis, so it goes out after.
    Coroutine::create(static fn() => $followers->welcome($connection));

    return true;
});

// A follower sends nothing the protocol needs; Swoole answers pings on its own.
$server->on('message', static function (): void {});

$server->on('close', static function (Server $server, int $connection) use (&$live): void {
    $live?->forget($connection);
});

$server->start();
