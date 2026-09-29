import type { Profile, Session } from '../../src/session/index.ts';
import type {
  Order,
  OrderStatus,
  OrderSummary,
  OrderTransition,
  Product,
  Store,
  Tracking,
} from '../../src/upstream/index.ts';

/**
 * What the services answer, already read by the upstream module: the inputs of the
 * screens behind each example in contracts/http/bff/examples.
 */

/** The stores the catalog seeds (ADR 0031): the examples shop in Arara Livros. */
export const arara: Store = {
  slug: 'arara',
  name: 'Arara Livros',
  tagline: 'Livros para quem constrói sistemas.',
  palette: 'arara',
};

export const bemtevi: Store = {
  slug: 'bemtevi',
  name: 'Bem-te-vi Eletrônicos',
  tagline: 'Eletrônicos para a mesa de trabalho.',
  palette: 'bemtevi',
};

export const sabia: Store = {
  slug: 'sabia',
  name: 'Sabiá Casa e Esporte',
  tagline: 'Da cozinha ao treino, o que o dia pede.',
  palette: 'sabia',
};

/** Every store of the platform, sorted by name, as the catalog lists them. */
export const everyStore: readonly Store[] = [arara, bemtevi, sabia];

export const dddBook: Product = {
  sku: 'BOOK-DDD-001',
  name: 'Domain-Driven Design',
  status: 'active',
  category: 'books',
  price: { amount: 15990, currency: 'BRL' },
  weightGrams: 1100,
  dimensions: { lengthMm: 240, widthMm: 170, heightMm: 40 },
};

export const releaseIt: Product = {
  sku: 'BOOK-REL-001',
  name: 'Release It!',
  status: 'active',
  category: 'books',
  price: { amount: 21990, currency: 'BRL' },
  weightGrams: 700,
  dimensions: { lengthMm: 230, widthMm: 190, heightMm: 25 },
};

/** A product of Sabiá: the other store a shopper of the tests buys in. */
export const mug: Product = {
  sku: 'HOME-MUG-001',
  name: 'Caneca de cerâmica',
  status: 'active',
  category: 'home',
  price: { amount: 4990, currency: 'BRL' },
  weightGrams: 400,
  dimensions: { lengthMm: 120, widthMm: 100, heightMm: 100 },
};

/** The shoppers of one browser: Ana and Bruno named, and a visitor the checkout started. */
export const ana: Profile = { id: '0199a2b4-5a1e-7c3d-8e4f-a1b2c3d4e5f6', name: 'Ana' };
export const bruno: Profile = { id: '0199a2b4-5b2f-7d4e-9f50-b2c3d4e5f607', name: 'Bruno' };
export const visitor: Profile = { id: '0199a2b4-5c30-7e5f-a061-c3d4e5f60718', name: null };

/** Ana shopping, in a browser that also holds Bruno and a visitor. */
export const anaShopping: Session = { active: ana.id, profiles: [ana, bruno, visitor] };
/** The session a first checkout starts: one profile, no name yet. */
export const visitorShopping: Session = { active: visitor.id, profiles: [visitor] };

export const pendingOrder: Order = {
  orderId: '0199a2b4-6f1c-7a3e-9b2d-5c8e1f4a7d20',
  orderNumber: '97856663872212992',
  status: 'pending_payment',
  lines: [
    {
      sku: 'BOOK-DDD-001',
      name: 'Domain-Driven Design',
      quantity: 1,
      unitPrice: { amount: 15990, currency: 'BRL' },
      subtotal: { amount: 15990, currency: 'BRL' },
    },
  ],
  total: { amount: 15990, currency: 'BRL' },
  placedAt: '2026-09-28T05:10:11.000Z',
  reservationExpiresAt: '2026-09-28T05:25:11.000Z',
  trackingCode: null,
  cancellationReason: null,
};

/** The same order further along its story: paid, shipped by a partner or by the own fleet, done. */
export const paidOrder: Order = { ...pendingOrder, status: 'paid' };
export const shippedOrder: Order = {
  ...pendingOrder,
  status: 'shipped',
  trackingCode: 'TX02PX83TXC5G00',
};
export const ownFleetOrder: Order = {
  ...pendingOrder,
  status: 'shipped',
  trackingCode: 'TX02Q6AGJQ45G00',
};
export const deliveredOrder: Order = { ...shippedOrder, status: 'delivered' };
export const declinedOrder: Order = {
  ...pendingOrder,
  status: 'cancelled',
  cancellationReason: 'payment_declined',
};

/** The status transitions Commerce keeps for the order, oldest first, at each point of its story. */
export const histories = {
  pending: [transition('pending_payment', '2026-09-28T05:10:11.000Z')],
  paid: [
    transition('pending_payment', '2026-09-28T05:10:11.000Z'),
    transition('paid', '2026-09-28T05:10:13.482Z'),
  ],
  shipped: [
    transition('pending_payment', '2026-09-28T05:10:11.000Z'),
    transition('paid', '2026-09-28T05:10:13.482Z'),
    transition('shipped', '2026-09-28T05:10:19.811Z'),
  ],
  delivered: [
    transition('pending_payment', '2026-09-28T05:10:11.000Z'),
    transition('paid', '2026-09-28T05:10:13.482Z'),
    transition('shipped', '2026-09-28T05:10:19.811Z'),
    transition('delivered', '2026-09-28T05:10:31.702Z'),
  ],
  declined: [
    transition('pending_payment', '2026-09-28T05:10:11.000Z'),
    transition('cancelled', '2026-09-28T05:10:14.230Z', 'payment_declined'),
  ],
} as const satisfies Record<string, readonly OrderTransition[]>;

/** The parcel of the order with a partner carrier, on its way through a hub. */
export const parcelInTransit: Tracking = {
  trackingCode: 'TX02PX83TXC5G00',
  status: 'in_transit',
  carrier: 'correio-nacional',
  destination: { municipality: 'Belo Horizonte', state: 'MG' },
  updatedAt: '2026-09-28T05:10:23.066Z',
  steps: [
    step('created', '2026-09-28T05:10:14.120Z'),
    step('ready_for_pickup', '2026-09-28T05:10:15.903Z'),
    step('picked_up', '2026-09-28T05:10:19.337Z'),
    { ...step('in_transit', '2026-09-28T05:10:23.066Z'), hub: 'Hub Contagem (MG)' },
  ],
};

/** The same parcel at the door. */
export const parcelDelivered: Tracking = {
  ...parcelInTransit,
  status: 'delivered',
  updatedAt: '2026-09-28T05:10:31.218Z',
  steps: [
    ...parcelInTransit.steps,
    { ...step('out_for_delivery', '2026-09-28T05:10:27.540Z'), attempt: 1 },
    { ...step('delivered', '2026-09-28T05:10:31.218Z'), attempt: 1 },
  ],
};

/** The parcel of the own fleet, with the courier on the way to the door: it can be followed live. */
export const parcelWithTheCourier: Tracking = {
  trackingCode: 'TX02Q6AGJQ45G00',
  status: 'out_for_delivery',
  carrier: 'tucano-express',
  destination: { municipality: 'Belo Horizonte', state: 'MG' },
  updatedAt: '2026-09-28T05:10:20.012Z',
  steps: [
    step('created', '2026-09-28T05:10:14.120Z'),
    step('ready_for_pickup', '2026-09-28T05:10:15.903Z'),
    step('picked_up', '2026-09-28T05:10:19.337Z'),
    { ...step('out_for_delivery', '2026-09-28T05:10:20.012Z'), attempt: 1 },
  ],
};

export const deliveredParcel: Tracking = {
  trackingCode: 'TX02PX83TXC5G00',
  status: 'delivered',
  carrier: 'correio-nacional',
  destination: { municipality: 'Salvador', state: 'BA' },
  updatedAt: '2026-09-28T00:50:19.827Z',
  steps: [
    step('created', '2026-09-28T00:47:27.467Z'),
    step('ready_for_pickup', '2026-09-28T00:47:29.156Z'),
    step('picked_up', '2026-09-28T00:47:32.867Z'),
    { ...step('in_transit', '2026-09-28T00:47:36.819Z'), hub: 'Hub Contagem (MG)' },
    { ...step('in_transit', '2026-09-28T00:48:09.571Z'), hub: 'Hub Simões Filho (BA)' },
    { ...step('out_for_delivery', '2026-09-28T00:49:15.964Z'), attempt: 1 },
    { ...step('delivered', '2026-09-28T00:50:19.827Z'), attempt: 1 },
  ],
};

/**
 * The own fleet (tucano-express) skips the hubs a partner goes through (partners-sim
 * README): straight from picked_up to out_for_delivery, and its courier reports live.
 */
export const outForDeliveryOwnFleet: Tracking = {
  trackingCode: 'TX02Q6AGJQ45G00',
  status: 'out_for_delivery',
  carrier: 'tucano-express',
  destination: { municipality: 'Contagem', state: 'MG' },
  updatedAt: '2026-09-28T21:55:40.000Z',
  steps: [
    step('created', '2026-09-28T21:40:12.000Z'),
    step('ready_for_pickup', '2026-09-28T21:41:03.000Z'),
    step('picked_up', '2026-09-28T21:42:51.000Z'),
    { ...step('out_for_delivery', '2026-09-28T21:55:40.000Z'), attempt: 1 },
  ],
};

/** Two older orders of Ana in Arara, on the second page of her list: one delivered, one that expired. */
export const booksDelivered: OrderSummary = {
  orderId: '0199a1f0-3c2d-7b4e-8a5f-6d7e8f9a0b1c',
  orderNumber: '97856101234567168',
  status: 'delivered',
  cancellationReason: null,
  total: { amount: 43980, currency: 'BRL' },
  lines: [{ sku: 'BOOK-REL-001', name: 'Release It!', quantity: 2 }],
  placedAt: '2026-09-27T14:02:31.000Z',
  updatedAt: '2026-09-27T14:03:05.000Z',
};

export const booksExpired: OrderSummary = {
  orderId: '0199a1e8-0a1b-7c2d-9e3f-4a5b6c7d8e9f',
  orderNumber: '97856089876543488',
  status: 'cancelled',
  cancellationReason: 'reservation_expired',
  total: { amount: 31980, currency: 'BRL' },
  lines: [
    { sku: 'BOOK-DDD-001', name: 'Domain-Driven Design', quantity: 1 },
    { sku: 'BOOK-CLEAN-001', name: 'Clean Architecture', quantity: 1 },
  ],
  placedAt: '2026-09-27T13:40:02.000Z',
  updatedAt: '2026-09-27T13:55:02.000Z',
};

/** An order of Ana in Sabiá: the same shopper, in the other store. */
export const mugsInSabia: OrderSummary = {
  orderId: '0199a2b0-1d2e-7f30-8a41-b2c3d4e5f607',
  orderNumber: '97856555555555328',
  status: 'paid',
  cancellationReason: null,
  total: { amount: 9980, currency: 'BRL' },
  lines: [{ sku: 'HOME-MUG-001', name: 'Caneca de cerâmica', quantity: 2 }],
  placedAt: '2026-09-28T03:12:40.000Z',
  updatedAt: '2026-09-28T03:12:44.000Z',
};

function step(status: Tracking['steps'][number]['status'], at: string): Tracking['steps'][number] {
  return { status, at, hub: null, attempt: null, reason: null };
}

function transition(
  status: OrderStatus,
  at: string,
  reason: string | null = null,
): OrderTransition {
  return { status, at, reason };
}

/** The same order as Commerce sends it over HTTP, before the upstream module reads it. */
export function orderJson(order: Order): Record<string, unknown> {
  return {
    ...order,
    placedAt: offset(order.placedAt),
    reservationExpiresAt: offset(order.reservationExpiresAt),
    customer: {
      id: '0199a2b4-1111-7222-8333-444455556666',
      name: 'Ana Souza',
      email: 'ana@example.com',
    },
  };
}

/** The order of a customer as the scoped route of Commerce sends it: the order and its history. */
export function customerOrderJson(
  order: Order,
  history: readonly OrderTransition[],
): Record<string, unknown> {
  return {
    ...orderJson(order),
    history: history.map((moved) => ({ ...moved, at: offset(moved.at) })),
  };
}

/** An order of the list as the read model of Commerce sends it. */
export function summaryJson(summary: OrderSummary): Record<string, unknown> {
  return { ...summary, placedAt: offset(summary.placedAt), updatedAt: offset(summary.updatedAt) };
}

/** A tracking page as Logistics sends it: what a step does not have is left out. */
export function trackingJson(tracking: Tracking): Record<string, unknown> {
  return {
    ...tracking,
    updatedAt: offset(tracking.updatedAt),
    steps: tracking.steps.map(({ hub, attempt, reason, ...rest }) => ({
      ...rest,
      ...(hub === null ? {} : { hub }),
      ...(attempt === null ? {} : { attempt }),
      ...(reason === null ? {} : { reason }),
    })),
  };
}

/** A product as the catalog sends it over HTTP, with the store it belongs to. */
export function productJson(product: Product, store: Store = arara): Record<string, unknown> {
  return {
    id: '01a0e282-e072-702a-93aa-0d5606613eb8',
    ...product,
    store: store.slug,
    version: 2,
    updatedAt: '2026-09-27T12:14:25.467Z',
  };
}

/** Services write instants with an offset; the BFF gives them back in UTC with a Z. */
function offset(instant: string): string {
  return instant.replace('Z', '+00:00');
}
