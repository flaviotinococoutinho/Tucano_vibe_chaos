"""Actions that need more than one HTTP call. Faults themselves are plain HTTP in the
experiment files: a toxic on Toxiproxy, a control on the partners simulator."""

from __future__ import annotations

from . import probes
from .store import Shopper, logger


def pay_while_the_psp_is_slow(attempts: int = 5) -> list[int]:
    """Pays some orders while the PSP is slow, which is what opens the circuit breaker.

    Each attempt waits for Commerce to give up on the PSP (its 2 s timeout); after enough
    failures in the window, the breaker opens and the next payment is refused at once.
    """
    statuses = []
    for attempt in range(1, attempts + 1):
        shopper = Shopper()
        order = shopper.place_order()
        payment = shopper.pay(order) if order.status == 201 else order
        logger.info("attempt %d of %d answered %s", attempt, attempts, payment.described())
        statuses.append(payment.status)
    return statuses


def buy_a_parcel() -> str:
    """Buys a mug while the fault is on, for the probe after the method to follow to the door."""
    order = probes._a_paid_order(Shopper())
    if order is None:
        raise RuntimeError("could not buy the parcel to follow")
    probes._bought_during_the_fault = order
    logger.info("bought a parcel to follow: %s", order)
    return order

