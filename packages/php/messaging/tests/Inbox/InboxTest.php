<?php

declare(strict_types=1);

namespace Tucano\Messaging\Tests\Inbox;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tucano\Messaging\Inbox\Inbox;
use Tucano\Messaging\Tests\Doubles\Postgres;

#[CoversClass(Inbox::class)]
#[Group('integration')]
final class InboxTest extends TestCase
{
    #[Test]
    public function a_redelivered_message_is_recognized_per_consumer(): void
    {
        $inbox = new Inbox(Postgres::connect());

        self::assertTrue($inbox->firstTime('logistics.order-intake', 'event-1'));
        self::assertFalse($inbox->firstTime('logistics.order-intake', 'event-1'));
        self::assertTrue($inbox->firstTime('commerce.order-projector', 'event-1'));
    }
}
