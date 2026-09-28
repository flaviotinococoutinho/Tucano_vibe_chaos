<?php

declare(strict_types=1);

namespace Tracking\Delivery\Adapter\Driving\WebSocket;

use Closure;
use Psr\Log\LoggerInterface;
use Tracking\Delivery\Adapter\DeliveryNewsJson;
use Tracking\Delivery\Application\Port\Driving\ForFollowingDeliveries;
use Tracking\Delivery\Domain\DeliveriesUnavailable;
use Tracking\Delivery\Domain\DeliveryEnded;
use Tracking\Delivery\Domain\DeliveryNews;
use Tracking\Delivery\Domain\TrackingCode;
use Tracking\Platform\Http\ValidationFailed;

/**
 * The WebSocket connections of one worker, by the tracking code each one follows. In BASE mode
 * a worker can push only to its own connections, so each worker keeps its own followers, and
 * the news reaches every worker through the deliveries channel (DeliveriesSubscription).
 */
final class LiveFollowers
{
    /** RFC 6455 close codes: the delivery is over, and the server is going away. */
    private const int NORMAL_CLOSURE = 1000;
    private const int GOING_AWAY = 1001;

    /** @var array<int, string> the code each connection follows */
    private array $codeOf = [];

    /** @var array<string, array<int, true>> the connections that follow each code */
    private array $connectionsOf = [];

    /**
     * @param Closure(int, string): bool $push sends one text frame to a connection
     * @param Closure(int, int, string): bool $close closes a connection with a code and a reason
     */
    public function __construct(
        private readonly Closure $push,
        private readonly Closure $close,
        private readonly ForFollowingDeliveries $deliveries,
        private readonly LoggerInterface $logger,
    ) {}

    public function follow(int $connection, TrackingCode $code): void
    {
        $this->codeOf[$connection] = $code->value;
        $this->connectionsOf[$code->value][$connection] = true;
    }

    /** What a follower sees first (UC-TRK-03, step 3): the last news, when there is one. */
    public function welcome(int $connection): void
    {
        $code = $this->codeOf[$connection] ?? null;
        if ($code === null) {
            return;
        }
        try {
            $last = $this->deliveries->lastNews(TrackingCode::of($code));
        } catch (DeliveriesUnavailable $unavailable) {
            // The follower stays connected: the next news still reaches it through the channel.
            $this->logger->warning('No last news for a new follower: {cause}', ['cause' => $unavailable->getPrevious()?->getMessage()]);

            return;
        }
        if ($last !== null) {
            $this->send($connection, $last, DeliveryNewsJson::encode($last));
        }
    }

    /** One news from the channel, pushed to every connection of this worker that follows its code. */
    public function deliver(string $json): void
    {
        try {
            $news = DeliveryNewsJson::decode($json);
        } catch (ValidationFailed $invalid) {
            $this->logger->warning('A news on the deliveries channel is not a delivery news', ['errors' => $invalid->errors]);

            return;
        }
        foreach (array_keys($this->connectionsOf[$news->trackingCode()->value] ?? []) as $connection) {
            $this->send($connection, $news, $json);
        }
    }

    public function forget(int $connection): void
    {
        $code = $this->codeOf[$connection] ?? null;
        if ($code === null) {
            return;
        }
        unset($this->codeOf[$connection], $this->connectionsOf[$code][$connection]);
        if (($this->connectionsOf[$code] ?? []) === []) {
            unset($this->connectionsOf[$code]);
        }
    }

    /** On shutdown every follower hears 1001 and connects again, to another worker or instance. */
    public function letEveryoneGo(): void
    {
        foreach (array_keys($this->codeOf) as $connection) {
            ($this->close)($connection, self::GOING_AWAY, 'The server is restarting.');
        }
        $this->codeOf = [];
        $this->connectionsOf = [];
    }

    public function count(): int
    {
        return count($this->codeOf);
    }

    private function send(int $connection, DeliveryNews $news, string $json): void
    {
        ($this->push)($connection, $json);
        if ($news instanceof DeliveryEnded) {
            // UC-TRK-03, step 5: the customer is told, and the connection ends with the delivery.
            ($this->close)($connection, self::NORMAL_CLOSURE, 'The delivery is over.');
            $this->forget($connection);
        }
    }
}
