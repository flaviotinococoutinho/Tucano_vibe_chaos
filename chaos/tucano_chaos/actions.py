"""Actions that need more than one HTTP call. Faults themselves are plain HTTP in the
experiment files: a toxic on Toxiproxy, a control on the partners simulator."""

from __future__ import annotations

import threading
import time
from collections import Counter

import requests

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
    shopper = Shopper()
    order = probes._a_paid_order(shopper)
    if order is None:
        raise RuntimeError("could not buy the parcel to follow")
    # The same browser follows it later: only the session that bought an order can open it.
    probes._bought_during_the_fault = probes.Followed(shopper, order)
    logger.info("bought a parcel to follow: %s", order)
    return order



def rush_a_store(store: str = "arara", neighbor: str = "sabia", seconds: float = 15.0, shoppers: int = 40) -> dict[str, int]:
    """A store on sale: many shoppers open its catalog at once, for a while, without a pause.

    Meanwhile one shopper of a neighbor store keeps opening that store's catalog, four times a
    second, and the probe after the method reads how fast it answered. Returns how the crowded
    store answered, by status, for the journal: a 429 there is the store's own limit at work.
    """
    crowded = f"/bff/v1/stores/{store}/products"
    calm = f"/bff/v1/stores/{neighbor}/products"
    deadline = time.monotonic() + seconds
    answered: Counter[int] = Counter()
    lock = threading.Lock()

    def shop() -> None:
        shopper = Shopper(timeout_seconds=10)
        while time.monotonic() < deadline:
            try:
                status = shopper.open(crowded).status
            except requests.RequestException:
                status = 0
            with lock:
                answered[status] += 1

    def watch() -> None:
        shopper = Shopper(timeout_seconds=10)
        while time.monotonic() < deadline:
            started = time.monotonic()
            try:
                answer = shopper.open(calm)
                probes._neighbor_during_the_rush.append((answer.status, answer.seconds))
            except requests.RequestException:
                probes._neighbor_during_the_rush.append((0, time.monotonic() - started))
            time.sleep(0.25)

    probes._neighbor_during_the_rush.clear()
    crowd = [threading.Thread(target=shop) for _ in range(shoppers)] + [threading.Thread(target=watch)]
    for thread in crowd:
        thread.start()
    for thread in crowd:
        thread.join()
    summary = {str(code): count for code, count in sorted(answered.items())}
    logger.info("the rush on %s answered %s", store, summary)
    return summary
