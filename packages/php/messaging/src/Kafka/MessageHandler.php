<?php

declare(strict_types=1);

namespace Tucano\Messaging\Kafka;

interface MessageHandler
{
    /**
     * Throw PermanentFailure for messages that will never succeed (bad payload);
     * any other exception is treated as transient and retried.
     */
    public function handle(ReceivedMessage $message): void;
}
