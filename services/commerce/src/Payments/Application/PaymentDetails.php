<?php

declare(strict_types=1);

namespace Commerce\Payments\Application;

use Commerce\Payments\Domain\Payment;

/** What the outside world sees of a payment. The provider's charge id stays inside. */
final readonly class PaymentDetails
{
    /** @param array{paymentId: string, orderId: string, status: string, amount: array{amount: int, currency: string}} $fields */
    private function __construct(private array $fields) {}

    public static function of(Payment $payment): self
    {
        return new self([
            'paymentId' => $payment->id->toString(),
            'orderId' => $payment->orderId,
            'status' => $payment->status->value,
            'amount' => $payment->amount->toArray(),
        ]);
    }

    public function paymentId(): string
    {
        return $this->fields['paymentId'];
    }

    /** @return array{paymentId: string, orderId: string, status: string, amount: array{amount: int, currency: string}} */
    public function toArray(): array
    {
        return $this->fields;
    }
}
