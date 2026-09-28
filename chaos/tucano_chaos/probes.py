"""The probes of the steady state: what a customer must still get while something breaks.

Each probe answers True or False, and logs what it saw, so the journal says whether the
hypothesis held and the log says by how much.
"""

from __future__ import annotations

import time

from .store import Answer, Shopper, logger, self_link

# The order a probe keeps between the check before the fault and the one after it, for the
# screens that must still answer when the service behind them is out.
_remembered_order: str | None = None


def catalog_opens(within_seconds: float = 2.0) -> bool:
    """The catalog is the door of the store: it must open while the rest suffers."""
    answer = Shopper().open("/bff/v1/products")
    logger.info("catalog answered %s", answer.described())
    return answer.status == 200 and answer.seconds <= within_seconds


def screen_answers(within_seconds: float = 6.0, screen: str = "/bff/v1/products") -> bool:
    """A screen answers in time: with its content, or with a 503 that says when to try again."""
    answer = Shopper().open(screen)
    logger.info("%s answered %s", screen, answer.described())
    return _content_or_honest_refusal(answer, within_seconds)


def order_screen_answers(within_seconds: float = 6.0) -> bool:
    """The order screen answers in time, with the order or with a 503 and Retry-After.

    The order is placed on the first check, while Commerce is reachable, and read again
    on the next one, when it may not be.
    """
    global _remembered_order
    shopper = Shopper()
    if _remembered_order is None:
        placed = shopper.place_order()
        if placed.status != 201:
            logger.info("could not place the order to watch: %s", placed.described())
            return False
        _remembered_order = self_link(placed.screen)
    answer = shopper.open(_remembered_order)
    logger.info("the order screen answered %s", answer.described())
    return _content_or_honest_refusal(answer, within_seconds)


def paying_answers_quickly(within_seconds: float = 1.0) -> bool:
    """Paying answers fast: accepted, or refused at once with a 503 and Retry-After.

    Waiting on a slow PSP is the one answer that is not allowed: it holds a PHP worker
    and a customer for the whole timeout.
    """
    shopper = Shopper()
    order = shopper.place_order()
    if order.status != 201:
        logger.info("could not place the order to pay: %s", order.described())
        return False
    payment = shopper.pay(order)
    logger.info("paying answered %s", payment.described())
    in_time = payment.seconds <= within_seconds
    return in_time and (payment.status == 202 or _is_honest_refusal(payment))


def payment_reaches_an_outcome(within_seconds: float = 90.0) -> bool:
    """A payment the PSP settled reaches the order, by webhook or by reconciliation.

    The probe pays with a card the PSP declines: the outcome comes back fast when the
    webhook arrives, and only through the reconciliation when it does not.
    """
    shopper = Shopper()
    order = shopper.place_order()
    if order.status != 201:
        logger.info("could not place the order to pay: %s", order.described())
        return False
    payment = shopper.pay(order)
    if payment.status != 202:
        logger.info("the payment was not accepted: %s", payment.described())
        return False

    started = time.monotonic()
    following = self_link(payment.screen)
    while time.monotonic() - started <= within_seconds:
        screen = shopper.open(following)
        if screen.status == 200 and screen.screen["properties"]["status"] != "pending_payment":
            waited = time.monotonic() - started
            logger.info(
                "the order reached %s after %.1f s", screen.screen["properties"]["status"], waited
            )
            return True
        time.sleep(2)
    logger.info("no outcome after %.0f s", within_seconds)
    return False


def _content_or_honest_refusal(answer: Answer, within_seconds: float) -> bool:
    return answer.seconds <= within_seconds and (answer.status == 200 or _is_honest_refusal(answer))


def _is_honest_refusal(answer: Answer) -> bool:
    """A 503 is an acceptable answer only when it says when to come back."""
    return answer.status == 503 and answer.retry_after is not None
