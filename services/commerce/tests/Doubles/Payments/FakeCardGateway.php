<?php

declare(strict_types=1);

namespace Tests\Doubles\Payments;

use Commerce\Payments\Application\Port\Driven\ForChargingCards;
use Commerce\Payments\Domain\CardToken;
use Commerce\Payments\Domain\GatewayFailure;
use Commerce\Payments\Domain\GatewayUnavailable;
use Commerce\Payments\Domain\PaymentId;
use Tucano\SharedKernel\Money\Money;

/** Takes every charge, until the test makes it time out once or go down. */
final class FakeCardGateway implements ForChargingCards
{
    /** @var list<string> the payment ids sent, in order */
    public private(set) array $charged = [];

    private int $timeoutsLeft = 0;

    private int $downForSeconds = 0;

    public function timesOutOnce(): void
    {
        $this->timeoutsLeft = 1;
    }

    public function goesDownFor(int $seconds): void
    {
        $this->downForSeconds = $seconds;
    }

    public function ensureAvailable(): void
    {
        if ($this->downForSeconds > 0) {
            throw GatewayUnavailable::forSeconds($this->downForSeconds);
        }
    }

    public function charge(PaymentId $payment, Money $amount, CardToken $card): string
    {
        $this->charged[] = $payment->toString();
        if ($this->timeoutsLeft > 0) {
            $this->timeoutsLeft--;

            throw GatewayFailure::because('timed out');
        }

        return 'ch_' . substr(hash('sha256', $payment->toString()), 0, 26);
    }
}
