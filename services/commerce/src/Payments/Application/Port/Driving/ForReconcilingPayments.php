<?php

declare(strict_types=1);

namespace Commerce\Payments\Application\Port\Driving;

use Commerce\Payments\Application\ReconciledPayment;
use Commerce\Payments\Domain\GatewayUnavailable;

interface ForReconcilingPayments
{
    /**
     * Asks the provider about the next payment still missing its final word, and acts on the
     * answer. Null when no payment is due.
     *
     * @throws GatewayUnavailable the circuit is open: nothing was claimed
     */
    public function reconcileNext(): ?ReconciledPayment;
}
