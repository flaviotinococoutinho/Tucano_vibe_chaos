<?php

declare(strict_types=1);

namespace Tucano\Messaging\Tests\Kafka;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tucano\Messaging\Kafka\Message;
use Tucano\Messaging\Kafka\MessageHandler;
use Tucano\Messaging\Kafka\PermanentFailure;
use Tucano\Messaging\Kafka\RdKafkaConsumer;
use Tucano\Messaging\Kafka\RdKafkaProducer;
use Tucano\Messaging\Kafka\ReceivedMessage;
use Tucano\Messaging\Kafka\RetryPolicy;
use Tucano\Messaging\Worker\StopSignal;

/** Needs a real broker that auto-creates topics (the CI one does). */
#[CoversClass(RdKafkaProducer::class)]
#[CoversClass(RdKafkaConsumer::class)]
#[CoversClass(ReceivedMessage::class)]
#[Group('integration')]
final class KafkaRoundTripTest extends TestCase
{
    private string $brokers;

    protected function setUp(): void
    {
        $this->brokers = (string) getenv('MESSAGING_KAFKA_BROKERS');
        if ($this->brokers === '') {
            self::markTestSkipped('Set MESSAGING_KAFKA_BROKERS to a broker that auto-creates topics.');
        }
    }

    #[Test]
    public function what_the_producer_sends_the_consumer_receives_with_headers(): void
    {
        $topic = 'tests.round-trip.' . bin2hex(random_bytes(4));
        $producer = new RdKafkaProducer($this->brokers, 'messaging-tests');
        $producer->send(new Message($topic, 'order-1', '{"hello":"kafka"}', ['correlation_id' => 'req-9#1']));
        $producer->flush();

        $received = $this->consumeOne($topic, 'tests-' . bin2hex(random_bytes(4)), static fn() => null);

        self::assertSame('order-1', $received->key);
        self::assertSame('{"hello":"kafka"}', $received->payload);
        self::assertSame('req-9#1', $received->headers['correlation_id']);
    }

    #[Test]
    public function a_poison_message_goes_to_the_dead_letter_topic(): void
    {
        $topic = 'tests.poison.' . bin2hex(random_bytes(4));
        $group = 'tests-' . bin2hex(random_bytes(4));
        $producer = new RdKafkaProducer($this->brokers, 'messaging-tests');
        $producer->send(new Message($topic, 'order-2', 'not json'));
        $producer->flush();

        $this->consumeOne($topic, $group, static fn() => throw new PermanentFailure('payload is not a CloudEvent'));
        $deadLetter = $this->consumeOne('dlq.' . $group, $group . '-inspector', static fn() => null);

        self::assertSame('not json', $deadLetter->payload);
        self::assertSame($topic, $deadLetter->headers['dlq-original-topic']);
        self::assertSame('payload is not a CloudEvent', $deadLetter->headers['dlq-error']);
    }

    #[Test]
    public function a_paused_consumer_takes_nothing_and_resumes_where_its_group_stopped(): void
    {
        $topic = 'tests.pause.' . bin2hex(random_bytes(4));
        $producer = new RdKafkaProducer($this->brokers, 'messaging-tests');
        $producer->send(new Message($topic, 'order-3', 'first'));
        $producer->flush();
        $stop = new StopSignal();
        $handler = new class ($stop) implements MessageHandler {
            /** @var list<string> */
            public array $payloads = [];

            public function __construct(private readonly StopSignal $stop) {}

            public function handle(ReceivedMessage $message): void
            {
                $this->payloads[] = $message->payload;
                if (count($this->payloads) === 2) {
                    $this->stop->stop();
                }
            }
        };
        $pausedPolls = 0;
        $polls = 0;
        // Three polls of pause right after the first message, and the second one arrives meanwhile.
        $paused = static function () use ($handler, $producer, $topic, $stop, &$pausedPolls, &$polls): bool {
            if (++$polls > 300) {
                $stop->stop();
            }
            if ($handler->payloads === [] || $pausedPolls === 3) {
                return false;
            }
            if (++$pausedPolls === 1) {
                $producer->send(new Message($topic, 'order-3', 'second'));
                $producer->flush();
            }

            return true;
        };
        $consumer = new RdKafkaConsumer(
            $this->brokers,
            'tests-' . bin2hex(random_bytes(4)),
            [$topic],
            new RdKafkaProducer($this->brokers, 'messaging-tests-dlq'),
            new NullLogger(),
            new RetryPolicy(maxAttempts: 1),
            pollTimeoutMs: 200,
        );

        $consumer->run($handler, $stop, $paused);

        self::assertSame(3, $pausedPolls);
        self::assertSame(['first', 'second'], $handler->payloads, 'the pause takes nothing, and the group goes on after the committed first message');
    }

    /** @param Closure(ReceivedMessage): void $behaviour */
    private function consumeOne(string $topic, string $group, Closure $behaviour): ReceivedMessage
    {
        $stop = new StopSignal();
        $handler = new class ($stop, $behaviour) implements MessageHandler {
            public ?ReceivedMessage $received = null;

            /** @param Closure(ReceivedMessage): void $behaviour */
            public function __construct(private readonly StopSignal $stop, private readonly Closure $behaviour) {}

            public function handle(ReceivedMessage $message): void
            {
                $this->received = $message;
                $this->stop->stop();
                ($this->behaviour)($message);
            }
        };
        $consumer = new RdKafkaConsumer(
            $this->brokers,
            $group,
            [$topic],
            new RdKafkaProducer($this->brokers, 'messaging-tests-dlq'),
            new NullLogger(),
            new RetryPolicy(maxAttempts: 1),
        );

        $consumer->run($handler, $stop);

        self::assertNotNull($handler->received, 'nothing was consumed');

        return $handler->received;
    }
}
