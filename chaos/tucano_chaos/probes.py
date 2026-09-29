"""The probes of the steady state: what a customer must still get while something breaks.

Each probe answers True or False, and logs what it saw, so the journal says whether the
hypothesis held and the log says by how much.
"""

from __future__ import annotations

import time

from .store import APPROVED_CARD, TRACK, Answer, Shopper, link_to, logger, self_link

# What a probe keeps between the check before the fault and the one after it, for the
# screens that must still answer when the service behind them is out.
_remembered_order: str | None = None
_remembered_tracking: str | None = None
# The order bought while the fault is on, which the probe after the method follows.
_bought_during_the_fault: str | None = None

# The steps every journey of the own fleet goes through, from the shipment to the door.
JOURNEY = ("created", "ready_for_pickup", "picked_up", "out_for_delivery", "delivered")


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


def placing_answers_quickly(within_seconds: float = 2.0) -> bool:
    """Placing an order answers fast: the order, or a 503 that says when to come back.

    Without its database, Commerce cannot keep a promise about stock, so refusing is the
    right answer. A 500 that tells the customer nothing is not.
    """
    shopper = Shopper()
    order = shopper.place_order()
    logger.info("placing the order answered %s", order.described())
    if order.status == 201:
        # Declined at once, the order is cancelled and the mug goes back to the shelf.
        shopper.pay(order)
    return order.seconds <= within_seconds and (order.status == 201 or _is_honest_refusal(order))


def tracking_screen_answers(
    within_seconds: float = 1.0, pickup_within_seconds: float = 90.0, or_refuses: bool = False
) -> bool:
    """The public tracking screen answers in time, read from its copy in DynamoDB.

    The first check buys a mug with the card that approves and follows the order until the
    carrier picks it up and the order offers its tracking link: the probe that spends stock.
    The next check only opens that link, while what is behind it may be out. With or_refuses,
    a 503 that says when to come back counts as an answer: when the copy itself is out, there
    is nothing to show, and refusing at once is the right thing to do.
    """
    global _remembered_tracking
    if _remembered_tracking is None:
        _remembered_tracking = _a_parcel_to_track(pickup_within_seconds)
        if _remembered_tracking is None:
            return False
    answer = Shopper().open(_remembered_tracking)
    logger.info("the tracking screen answered %s", answer.described())
    if or_refuses:
        return _content_or_honest_refusal(answer, within_seconds)
    return answer.status == 200 and answer.seconds <= within_seconds


def the_page_follows_the_journey(within_seconds: float = 90.0) -> bool:
    """The public tracking page follows a parcel to the door, with every step of the journey.

    It follows the order bought while the fault was on when there is one, and buys a new one
    otherwise, with the card that approves: a parcel has to ship. The page is read the way
    the web reads it, through the tracking link of the order screen.
    """
    shopper = Shopper()
    order = _bought_during_the_fault
    if order is None:
        order = _a_paid_order(shopper)
        if order is None:
            return False

    started = time.monotonic()
    tracking = None
    while time.monotonic() - started <= within_seconds:
        if tracking is None:
            screen = shopper.open(order)
            tracking = link_to(screen.screen, TRACK) if screen.status == 200 else None
            if tracking is not None:
                logger.info("the order got its tracking link after %.1f s", time.monotonic() - started)
        else:
            page = shopper.open(tracking)
            if page.status == 200:
                steps = [step["properties"]["status"] for step in page.screen.get("entities", [])]
                if page.screen["properties"]["status"] == "delivered":
                    missing = [step for step in JOURNEY if step not in steps]
                    waited = time.monotonic() - started
                    logger.info("the page reached delivered after %.1f s, with %s", waited, ", ".join(steps))
                    if missing:
                        logger.info("the page lost the steps %s", ", ".join(missing))
                    return not missing
        time.sleep(2)
    logger.info("the page did not reach delivered in %.0f s", within_seconds)
    return False


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


def _a_paid_order(shopper: Shopper) -> str | None:
    """Buys a mug with the card that approves, and returns the order screen to follow."""
    order = shopper.place_order()
    if order.status != 201:
        logger.info("could not place the order: %s", order.described())
        return None
    payment = shopper.pay(order, card=APPROVED_CARD)
    if payment.status != 202:
        logger.info("the payment was not accepted: %s", payment.described())
        return None
    return self_link(payment.screen)


def _a_parcel_to_track(within_seconds: float) -> str | None:
    shopper = Shopper()
    order = shopper.place_order()
    if order.status != 201:
        logger.info("could not place the order to track: %s", order.described())
        return None
    payment = shopper.pay(order, card=APPROVED_CARD)
    if payment.status != 202:
        logger.info("the payment was not accepted: %s", payment.described())
        return None

    started = time.monotonic()
    following = self_link(payment.screen)
    while time.monotonic() - started <= within_seconds:
        screen = shopper.open(following)
        tracking = link_to(screen.screen, TRACK) if screen.status == 200 else None
        if tracking is not None:
            logger.info("the carrier picked the order up after %.1f s", time.monotonic() - started)
            return tracking
        time.sleep(2)
    logger.info("no tracking link after %.0f s", within_seconds)
    return None


def _content_or_honest_refusal(answer: Answer, within_seconds: float) -> bool:
    return answer.seconds <= within_seconds and (answer.status == 200 or _is_honest_refusal(answer))


def _is_honest_refusal(answer: Answer) -> bool:
    """A 503 is an acceptable answer only when it says when to come back."""
    return answer.status == 503 and answer.retry_after is not None
