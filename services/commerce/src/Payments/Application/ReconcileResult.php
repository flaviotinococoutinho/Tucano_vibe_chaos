<?php

declare(strict_types=1);

namespace Commerce\Payments\Application;

/** What reconciliation did with one payment. The worker logs it, and a person reads it. */
enum ReconcileResult: string
{
    /** The provider had the final word, and it is applied now, as a webhook would (UC-PAY-02). */
    case Settled = 'settled';

    /** A webhook got there first. */
    case AlreadySettled = 'already_settled';

    /** The provider is still processing the charge or its refund. */
    case InProgress = 'in_progress';

    /** No charge at the provider, but the order still waits: the customer can try again. */
    case Waiting = 'waiting';

    /** No charge at the provider, and the order stopped waiting: Tucano gives up on the payment. */
    case Abandoned = 'abandoned';

    /** The refund went to the provider, which confirms it later (UC-PAY-04). */
    case RefundSent = 'refund_sent';

    /** The provider did not answer; the payment is looked at again in a later round. */
    case NoAnswer = 'no_answer';

    /** What the provider says makes no sense for this payment: a person has to look. */
    case NeedsAttention = 'needs_attention';
}
