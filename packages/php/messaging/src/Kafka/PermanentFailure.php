<?php

declare(strict_types=1);

namespace Tucano\Messaging\Kafka;

use RuntimeException;

/** Retrying will not help (malformed payload, unknown version): straight to the DLQ. */
final class PermanentFailure extends RuntimeException {}
