<?php

declare(strict_types=1);

namespace Commerce\Payments\Application\UseCase;

use Commerce\Payments\Application\Port\Driven\ForStoringPayments;
use Commerce\Payments\Application\Port\Driving\ForRequestingRefunds;
use Commerce\Payments\Application\RefundRequest;
use Commerce\Shared\Application\Port\Driven\ForRunningTransactions;
use Tucano\SharedKernel\Documentation\UseCase;
use Tucano\SharedKernel\Time\Clock;

/**
 * The third way into UC-PAY-04: the order came back to the fulfillment center
 * (UC-ORD-04), so its captured payment asks for the money back. Only the mark
 * happens here, inside the transaction of the order; the call to the provider
 * is the reconciliation's, outside any transaction, like the other refunds.
 */
#[UseCase('UC-PAY-04')]
final readonly class RequestOrderRefund implements ForRequestingRefunds
{
    public function __construct(private ForRunningTransactions $transactions, private ForStoringPayments $payments, private Clock $clock) {}

    public function refundOrder(string $orderId): RefundRequest
    {
        return $this->transactions->run(function () use ($orderId): RefundRequest {
            $payment = $this->payments->capturedFor($orderId);
            if ($payment === null) {
                return RefundRequest::NothingToRefund;
            }
            $payment->requestRefund($this->clock->now());
            $this->payments->save($payment);

            return RefundRequest::Requested;
        });
    }
}
