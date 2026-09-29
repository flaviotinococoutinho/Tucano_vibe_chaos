import { checkoutScreen } from '../../src/checkout/index.ts';
import type { Entity } from '../../src/hypermedia/index.ts';
import { type Delivery, noOrders, orderScreen, ordersScreen } from '../../src/orders/index.ts';
import { navigationOf, profilesScreen, withNavigation } from '../../src/profiles/index.ts';
import type { Session } from '../../src/session/index.ts';
import {
  catalogScreen,
  homeScreen,
  productScreen,
  storeScreen,
} from '../../src/storefront/index.ts';
import { trackingScreen } from '../../src/tracking/index.ts';
import type { Store, Tracking } from '../../src/upstream/index.ts';
import {
  anaShopping,
  arara,
  booksDelivered,
  booksExpired,
  dddBook,
  declinedOrder,
  deliveredOrder,
  deliveredParcel,
  everyStore,
  histories,
  outForDeliveryOwnFleet,
  ownFleetOrder,
  paidOrder,
  parcelDelivered,
  parcelInTransit,
  parcelWithTheCourier,
  pendingOrder,
  releaseIt,
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

/**
 * A screen as the web receives it: with the navigation of the session that asked for it, in
 * the store the screen is in, or on the platform.
 */
export function seenBy(session: Session | null, store: Store | null, screen: Entity): Entity {
  return withNavigation(screen, navigationOf(session, store));
}

/**
 * Every example of the contract, by file, built by the functions the BFF answers with. The
 * home of the platform lists the stores, and every store screen is in Arara Livros. The
 * pages seen before any purchase go without a session, the checkout starts one for a
 * visitor, and Ana follows her orders, and opens the profiles from the store.
 */
export const EXAMPLE_SCREENS: Readonly<Record<string, () => Entity>> = {
  'home.json': () => seenBy(null, null, homeScreen(everyStore)),
  'store.json': () => seenBy(null, arara, storeScreen(arara)),
  'catalog.json': () =>
    seenBy(
      null,
      arara,
      catalogScreen(arara, { products: [dddBook, releaseIt], page: 1, perPage: 20, total: 5 }),
    ),
  'product.json': () => seenBy(null, arara, productScreen(arara, dddBook)),
  'checkout.json': () =>
    seenBy(visitorShopping, arara, checkoutScreen(arara, dddBook, 1, FORM_KEY)),
  'profiles.json': () => seenBy(anaShopping, null, profilesScreen(anaShopping, arara)),
  'orders.json': () =>
    seenBy(
      anaShopping,
      arara,
      ordersScreen(arara, {
        orders: [booksDelivered, booksExpired],
        page: 2,
        perPage: 10,
        total: 12,
      }),
    ),
  'orders-empty.json': () => seenBy(null, arara, ordersScreen(arara, noOrders(1))),
  'order-pending-payment.json': () =>
    seenBy(
      anaShopping,
      arara,
      orderScreen(
        arara,
        { order: pendingOrder, history: histories.pending, delivery: NO_NEWS },
        { awaitingPayment: false, key: PAY_KEY },
      ),
    ),
  'order-awaiting-payment.json': () =>
    seenBy(
      anaShopping,
      arara,
      orderScreen(
        arara,
        { order: pendingOrder, history: histories.pending, delivery: NO_NEWS },
        { awaitingPayment: true, key: PAY_KEY },
      ),
    ),
  'order-paid.json': () =>
    seenBy(
      anaShopping,
      arara,
      orderScreen(
        arara,
        { order: paidOrder, history: histories.paid, delivery: NO_NEWS },
        { awaitingPayment: true, key: PAY_KEY },
      ),
    ),
  'order-shipped.json': () =>
    seenBy(
      anaShopping,
      arara,
      orderScreen(
        arara,
        { order: shippedOrder, history: histories.shipped, delivery: newsOf(parcelInTransit) },
        { awaitingPayment: false, key: PAY_KEY },
      ),
    ),
  'order-out-for-delivery.json': () =>
    seenBy(
      anaShopping,
      arara,
      orderScreen(
        arara,
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
      arara,
      orderScreen(
        arara,
        { order: deliveredOrder, history: histories.delivered, delivery: newsOf(parcelDelivered) },
        { awaitingPayment: false, key: PAY_KEY },
      ),
    ),
  'order-without-delivery-news.json': () =>
    seenBy(
      anaShopping,
      arara,
      orderScreen(
        arara,
        { order: shippedOrder, history: histories.shipped, delivery: NEWS_MISSING },
        { awaitingPayment: false, key: PAY_KEY },
      ),
    ),
  'order-cancelled.json': () =>
    seenBy(
      anaShopping,
      arara,
      orderScreen(
        arara,
        { order: declinedOrder, history: histories.declined, delivery: NO_NEWS },
        { awaitingPayment: false, key: PAY_KEY },
      ),
    ),
  'tracking.json': () => seenBy(null, arara, trackingScreen(arara, deliveredParcel, LIVE_PATH)),
  'tracking-live.json': () =>
    seenBy(null, arara, trackingScreen(arara, outForDeliveryOwnFleet, LIVE_PATH)),
};
