import { checkoutScreen } from '../../src/checkout/index.ts';
import type { Entity } from '../../src/hypermedia/index.ts';
import { type Delivery, noOrders, orderScreen, ordersScreen } from '../../src/orders/index.ts';
import { navigationOf, profilesScreen, withNavigation } from '../../src/profiles/index.ts';
import type { Session } from '../../src/session/index.ts';
import { catalogScreen, homeScreen, productScreen } from '../../src/storefront/index.ts';
import { trackingScreen } from '../../src/tracking/index.ts';
import type { Tracking } from '../../src/upstream/index.ts';
import {
  anaShopping,
  booksExpired,
  coffeeMaker,
  dddBook,
  declinedOrder,
  deliveredOrder,
  deliveredParcel,
  histories,
  mugsDelivered,
  outForDeliveryOwnFleet,
  ownFleetOrder,
  paidOrder,
  parcelDelivered,
  parcelInTransit,
  parcelWithTheCourier,
  pendingOrder,
  shippedOrder,
  visitorShopping,
} from './upstream-data.ts';

/** The idempotency keys the examples carry, one per form. */
export const PAY_KEY = '0199a2b4-9b21-7d62-a1e3-4f5a6b7c8d9e';
export const FORM_KEY = '0199a2b4-8a10-7c51-b0d2-3e4f5a6b7c8d';

/** The path Kong serves the live WebSocket at; `config.ts` reads the same default. */
export const LIVE_PATH = '/api/tracking/v1/live';

const NO_NEWS: Delivery = { news: 'none' };
const NEWS_MISSING: Delivery = { news: 'unavailable' };

function newsOf(tracking: Tracking): Delivery {
  return { news: 'known', tracking, livePath: LIVE_PATH };
}

/** A screen as the web receives it: with the navigation of the session that asked for it. */
export function seenBy(session: Session | null, screen: Entity): Entity {
  return withNavigation(screen, navigationOf(session));
}

/**
 * Every example of the contract, by file, built by the functions the BFF answers with. The
 * pages seen before any purchase go without a session, the checkout starts one for a visitor,
 * and Ana follows her orders.
 */
export const EXAMPLE_SCREENS: Readonly<Record<string, () => Entity>> = {
  'home.json': () => seenBy(null, homeScreen()),
  'catalog.json': () =>
    seenBy(
      null,
      catalogScreen({ products: [dddBook, coffeeMaker], page: 1, perPage: 20, total: 16 }),
    ),
  'product.json': () => seenBy(null, productScreen(dddBook)),
  'checkout.json': () => seenBy(visitorShopping, checkoutScreen(dddBook, 1, FORM_KEY)),
  'profiles.json': () => seenBy(anaShopping, profilesScreen(anaShopping)),
  'orders.json': () =>
    seenBy(
      anaShopping,
      ordersScreen({ orders: [mugsDelivered, booksExpired], page: 2, perPage: 10, total: 12 }),
    ),
  'orders-empty.json': () => seenBy(null, ordersScreen(noOrders(1))),
  'order-pending-payment.json': () =>
    seenBy(
      anaShopping,
      orderScreen(
        { order: pendingOrder, history: histories.pending, delivery: NO_NEWS },
        { awaitingPayment: false, key: PAY_KEY },
      ),
    ),
  'order-awaiting-payment.json': () =>
    seenBy(
      anaShopping,
      orderScreen(
        { order: pendingOrder, history: histories.pending, delivery: NO_NEWS },
        { awaitingPayment: true, key: PAY_KEY },
      ),
    ),
  'order-paid.json': () =>
    seenBy(
      anaShopping,
      orderScreen(
        { order: paidOrder, history: histories.paid, delivery: NO_NEWS },
        { awaitingPayment: true, key: PAY_KEY },
      ),
    ),
  'order-shipped.json': () =>
    seenBy(
      anaShopping,
      orderScreen(
        { order: shippedOrder, history: histories.shipped, delivery: newsOf(parcelInTransit) },
        { awaitingPayment: false, key: PAY_KEY },
      ),
    ),
  'order-out-for-delivery.json': () =>
    seenBy(
      anaShopping,
      orderScreen(
        {
          order: ownFleetOrder,
          history: histories.shipped,
          delivery: newsOf(parcelWithTheCourier),
        },
        { awaitingPayment: false, key: PAY_KEY },
      ),
    ),
  'order-delivered.json': () =>
    seenBy(
      anaShopping,
      orderScreen(
        { order: deliveredOrder, history: histories.delivered, delivery: newsOf(parcelDelivered) },
        { awaitingPayment: false, key: PAY_KEY },
      ),
    ),
  'order-without-delivery-news.json': () =>
    seenBy(
      anaShopping,
      orderScreen(
        { order: shippedOrder, history: histories.shipped, delivery: NEWS_MISSING },
        { awaitingPayment: false, key: PAY_KEY },
      ),
    ),
  'order-cancelled.json': () =>
    seenBy(
      anaShopping,
      orderScreen(
        { order: declinedOrder, history: histories.declined, delivery: NO_NEWS },
        { awaitingPayment: false, key: PAY_KEY },
      ),
    ),
  'tracking.json': () => seenBy(null, trackingScreen(deliveredParcel, LIVE_PATH)),
  'tracking-live.json': () => seenBy(null, trackingScreen(outForDeliveryOwnFleet, LIVE_PATH)),
};
