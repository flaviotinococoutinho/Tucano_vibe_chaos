<?php

declare(strict_types=1);

namespace Tests\Doubles\Payments;

use Commerce\Payments\Application\Port\Driven\ForChargingCards;
use Commerce\Payments\Application\ProviderCharge;
use Commerce\Payments\Domain\CardToken;
use Commerce\Payments\Domain\ChargeRefused;
use Commerce\Payments\Domain\GatewayFailure;
use Commerce\Payments\Domain\GatewayUnavailable;
use Commerce\Payments\Domain\PaymentId;
use Tucano\SharedKernel\Money\Money;

/**
 * Takes every charge, until the test makes it time out once, go down, or refuse
 * refunds. What it reports about a charge is what the test says it has.
 */
final class FakeCardGateway implements ForChargingCards
{
    /** @var list<string> the payment ids sent, in order */
    public private(set) array $charged = [];

    /** @var list<array{payment: string, charge: string, cents: int}> the refunds asked for, in order */
    public private(set) array $refunds = [];

    /** @var array<string, ProviderCharge> payment id => the charge the provider has for it */
    private array $chargesAtProvider = [];

    private int $timeoutsLeft = 0;

    private int $downForSeconds = 0;

    private ?string $refundRefusal = null;

    public function timesOutOnce(): void
    {
        $this->timeoutsLeft = 1;
    }

    public function goesDownFor(int $seconds): void
    {
        $this->downForSeconds = $seconds;
    }

    public function has(PaymentId $payment, ProviderCharge $charge): void
    {
        $this->chargesAtProvider[$payment->toString()] = $charge;
    }

    public function refusesRefunds(string $because): void
    {
        $this->refundRefusal = $because;
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
        $this->timeOutIfAsked();

        return self::chargeIdOf($payment);
    }

    public function chargeOf(PaymentId $payment): ?ProviderCharge
    {
        $this->timeOutIfAsked();

        return $this->chargesAtProvider[$payment->toString()] ?? null;
    }

    public function refund(PaymentId $payment, string $chargeId, Money $amount): void
    {
        $this->timeOutIfAsked();
        if ($this->refundRefusal !== null) {
            throw ChargeRefused::because($this->refundRefusal);
        }
        $this->refunds[] = ['payment' => $payment->toString(), 'charge' => $chargeId, 'cents' => $amount->cents()];
    }

    /** The charge id this provider gives the payment, the same every time. */
    public static function chargeIdOf(PaymentId $payment): string
    {
        return 'ch_' . substr(hash('sha256', $payment->toString()), 0, 26);
    }

    private function timeOutIfAsked(): void
    {
        if ($this->timeoutsLeft > 0) {
            $this->timeoutsLeft--;

            throw GatewayFailure::because('timed out');
        }
    }
}
