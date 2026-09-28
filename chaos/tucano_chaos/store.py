"""A customer of the Tucano store, driven the way the web drives it.

It never builds an address: it opens the screens the BFF sends and follows their links
and actions (Siren). If a screen changes its flow, the experiments follow it as the web
does, without a line changed here.
"""

from __future__ import annotations

import logging
import os
import time
from dataclasses import dataclass
from typing import Any

import requests

logger = logging.getLogger("chaostoolkit")

# Inside the compose network the store is Kong; from the host it is localhost:8000.
STORE = os.environ.get("TUCANO_STORE", "http://kong:8000")

# The mug has the deepest stock, and the probes pay with a card the PSP declines, so a
# run gives every unit back: an experiment can run all day without emptying a shelf. Only
# the tracking probe pays with the card that approves, because a parcel has to ship.
PRODUCT = "HOME-MUG-001"
DECLINED_CARD = "tok_decline"
APPROVED_CARD = "tok_visa"

# The relation of the link from an order to the public tracking of its parcel.
TRACK = "https://github.com/flaviotinococoutinho/chaos_playground/blob/develop/contracts/http/bff/README.md#rel-track"

GUEST = {
    "name": "Cliente do Caos",
    "email": "caos@example.com",
    "postalCode": "30160-011",
    "thoroughfareType": "Rua",
    "thoroughfareName": "da Bahia",
    "number": "1200",
    "municipality": "Belo Horizonte",
    "state": "MG",
}


@dataclass(frozen=True)
class Answer:
    """What the store answered, and how long it took to answer."""

    status: int
    seconds: float
    body: dict[str, Any] | None
    retry_after: str | None

    @property
    def screen(self) -> dict[str, Any]:
        if self.body is None:
            raise RuntimeError(f"the store answered {self.status} without a screen")
        return self.body

    def described(self) -> str:
        wait = f", retry after {self.retry_after} s" if self.retry_after else ""
        return f"{self.status} in {self.seconds:.2f} s{wait}"


class Shopper:
    """One browser: a session keeps the guest cookie, as the web does."""

    def __init__(self, timeout_seconds: float = 30.0) -> None:
        self.session = requests.Session()
        self.timeout = timeout_seconds

    def open(self, href: str) -> Answer:
        return self._send("GET", href)

    def submit(self, action: dict[str, Any], values: dict[str, Any]) -> Answer:
        """Sends an action with its hidden fields, the idempotency key among them."""
        fields = {field["name"]: field["value"] for field in action["fields"] if "value" in field}
        fields.update(values)
        if action["method"] == "GET":
            return self._send("GET", action["href"], params=fields)
        return self._send(action["method"], action["href"], json=fields)

    def place_order(self) -> Answer:
        """From the checkout of the mug to the order screen, through the forms the BFF sends."""
        checkout = self.open(f"/bff/v1/checkout?sku={PRODUCT}&quantity=1")
        if checkout.status != 200:
            return checkout
        return self.submit(action_named(checkout.screen, "place-order"), GUEST)

    def pay(self, order: Answer, card: str = DECLINED_CARD) -> Answer:
        return self.submit(action_named(order.screen, "pay"), {"cardToken": card})

    def _send(self, method: str, href: str, **kwargs: Any) -> Answer:
        started = time.monotonic()
        response = self.session.request(
            method,
            f"{STORE}{href}",
            headers={"Accept": "application/vnd.siren+json"},
            timeout=self.timeout,
            **kwargs,
        )
        seconds = time.monotonic() - started
        body = response.json() if "json" in response.headers.get("Content-Type", "") else None
        return Answer(response.status_code, seconds, body, response.headers.get("Retry-After"))


def action_named(screen: dict[str, Any], name: str) -> dict[str, Any]:
    for action in screen.get("actions", []):
        if action["name"] == name:
            return action
    raise RuntimeError(f"the screen {screen.get('title')!r} offers no {name!r} action")


def self_link(screen: dict[str, Any]) -> str:
    href = link_to(screen, "self")
    if href is None:
        raise RuntimeError(f"the screen {screen.get('title')!r} has no self link")
    return href


def link_to(screen: dict[str, Any], rel: str) -> str | None:
    """The link of a relation, when the screen offers it: the order offers tracking only after pickup."""
    for link in screen.get("links", []):
        if rel in link["rel"]:
            return link["href"]
    return None
