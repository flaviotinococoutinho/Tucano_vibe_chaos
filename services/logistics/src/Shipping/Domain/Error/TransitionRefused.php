<?php

declare(strict_types=1);

namespace Logistics\Shipping\Domain\Error;

use Tucano\SharedKernel\Domain\DomainError;
use Tucano\SharedKernel\Domain\ErrorCategory;

/**
 * The move exists in the table, but a guard refused it with the data at hand.
 * Missing evidence is invalid input; a rule about attempts is a conflict with
 * the history of the shipment.
 */
final class TransitionRefused extends DomainError
{
    private function __construct(string $reason, private readonly ErrorCategory $category)
    {
        parent::__construct($reason);
    }

    public static function withoutLabel(): self
    {
        return new self('A shipment is ready for pickup only with its label attached.', ErrorCategory::InvalidInput);
    }

    public static function withoutHub(): self
    {
        return new self('A shipment in transit needs the hub that scanned it.', ErrorCategory::InvalidInput);
    }

    public static function withoutProofOfDelivery(): self
    {
        return new self('A delivery needs its proof: who received the shipment and their document.', ErrorCategory::InvalidInput);
    }

    public static function withoutFailureReason(): self
    {
        return new self('A failed delivery needs the reason it failed.', ErrorCategory::InvalidInput);
    }

    public static function attemptsExhausted(int $limit): self
    {
        return new self(sprintf('A shipment goes out for delivery at most %d times.', $limit), ErrorCategory::Conflict);
    }

    public static function returnNotJustified(int $limit): self
    {
        return new self(sprintf('A shipment goes back to the sender only after %d failed attempts or a refusal.', $limit), ErrorCategory::Conflict);
    }

    public function category(): ErrorCategory
    {
        return $this->category;
    }
}
