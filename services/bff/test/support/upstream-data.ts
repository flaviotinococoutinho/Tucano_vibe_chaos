import type { Order, Product, Tracking } from '../../src/upstream/index.ts';

/**
 * What the services answer, already read by the upstream module: the inputs of the
 * screens behind each example in contracts/http/bff/examples.
 */
export const dddBook: Product = {
  sku: 'BOOK-DDD-001',
  name: 'Domain-Driven Design',
  status: 'active',
  category: 'books',
  price: { amount: 15990, currency: 'BRL' },
  weightGrams: 1100,
  dimensions: { lengthMm: 240, widthMm: 170, heightMm: 40 },
};

export const coffeeMaker: Product = {
  sku: 'HOME-COFFEE-001',
  name: 'Cafeteira elétrica',
  status: 'active',
  category: 'home',
  price: { amount: 34990, currency: 'BRL' },
  weightGrams: 2300,
  dimensions: { lengthMm: 300, widthMm: 200, heightMm: 350 },
};

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

function step(status: Tracking['status'], at: string): Tracking['steps'][number] {
  return { status, at, hub: null, attempt: null, reason: null };
}

/** The same order as Commerce sends it over HTTP, before the upstream module reads it. */
export function orderJson(order: Order): Record<string, unknown> {
  return {
    ...order,
    placedAt: order.placedAt.replace('Z', '+00:00'),
    reservationExpiresAt: order.reservationExpiresAt.replace('Z', '+00:00'),
    customer: {
      id: '0199a2b4-1111-7222-8333-444455556666',
      name: 'Ana Souza',
      email: 'ana@example.com',
    },
  };
}

/** A product as the catalog sends it over HTTP. */
export function productJson(product: Product): Record<string, unknown> {
  return {
    id: '01a0e282-e072-702a-93aa-0d5606613eb8',
    ...product,
    version: 2,
    updatedAt: '2026-09-27T12:14:25.467Z',
  };
}
