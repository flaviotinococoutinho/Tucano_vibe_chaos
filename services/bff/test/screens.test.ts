import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { checkoutScreen } from '../src/checkout/index.ts';
import { orderScreen } from '../src/orders/index.ts';
import { catalogScreen, homeScreen, productScreen } from '../src/storefront/index.ts';
import { trackingScreen } from '../src/tracking/index.ts';
import type { Order, Tracking } from '../src/upstream/index.ts';
import { example, wire } from './support/examples.ts';
import { coffeeMaker, dddBook, deliveredParcel, pendingOrder } from './support/upstream-data.ts';

const PAY_KEY = '0199a2b4-9b21-7d62-a1e3-4f5a6b7c8d9e';

/**
 * The examples of the contract are what the web renders in its tests. Each one here is
 * built by the same function the BFF answers with, so the contract, the BFF and the web
 * cannot drift apart without a test going red.
 */
describe('the screens of the contract', () => {
  it('home', () => {
    assert.deepStrictEqual(wire(homeScreen()), example('home.json'));
  });

  it('catalog', () => {
    const page = { products: [dddBook, coffeeMaker], page: 1, perPage: 20, total: 16 };
    assert.deepStrictEqual(wire(catalogScreen(page)), example('catalog.json'));
  });

  it('product', () => {
    assert.deepStrictEqual(wire(productScreen(dddBook)), example('product.json'));
  });

  it('checkout', () => {
    const screen = checkoutScreen(dddBook, 1, '0199a2b4-8a10-7c51-b0d2-3e4f5a6b7c8d');
    assert.deepStrictEqual(wire(screen), example('checkout.json'));
  });

  it('order waiting for payment', () => {
    const screen = orderScreen(pendingOrder, { awaitingPayment: false, key: PAY_KEY });
    assert.deepStrictEqual(wire(screen), example('order-pending-payment.json'));
  });

  it('order confirming the payment', () => {
    const screen = orderScreen(pendingOrder, { awaitingPayment: true, key: PAY_KEY });
    assert.deepStrictEqual(wire(screen), example('order-awaiting-payment.json'));
  });

  it('order just paid', () => {
    const paid: Order = { ...pendingOrder, status: 'paid' };
    const screen = orderScreen(paid, { awaitingPayment: true, key: PAY_KEY });
    assert.deepStrictEqual(wire(screen), example('order-paid.json'));
  });

  it('order on its way', () => {
    const shipped: Order = { ...pendingOrder, status: 'shipped', trackingCode: 'TX02PX83TXC5G00' };
    const screen = orderScreen(shipped, { awaitingPayment: false, key: PAY_KEY });
    assert.deepStrictEqual(wire(screen), example('order-shipped.json'));
  });

  it('order cancelled by a declined card', () => {
    const cancelled: Order = {
      ...pendingOrder,
      status: 'cancelled',
      cancellationReason: 'payment_declined',
    };
    const screen = orderScreen(cancelled, { awaitingPayment: false, key: PAY_KEY });
    assert.deepStrictEqual(wire(screen), example('order-cancelled.json'));
  });

  it('tracking', () => {
    assert.deepStrictEqual(wire(trackingScreen(deliveredParcel)), example('tracking.json'));
  });
});

describe('the order screen', () => {
  it('stops asking for news when the story ends', () => {
    for (const status of ['delivered', 'cancelled', 'returned'] as const) {
      const screen = orderScreen(
        { ...pendingOrder, status },
        { awaitingPayment: false, key: PAY_KEY },
      );
      assert.deepStrictEqual(screen.class, ['screen', 'order'], status);
      assert.equal(screen.properties?.refreshAfterSeconds, undefined, status);
    }
  });

  it('offers the pay form only while the order waits and nobody is paying yet', () => {
    const waiting = orderScreen(pendingOrder, { awaitingPayment: false, key: PAY_KEY });
    const confirming = orderScreen(pendingOrder, { awaitingPayment: true, key: PAY_KEY });
    const paid = orderScreen(
      { ...pendingOrder, status: 'paid' },
      { awaitingPayment: false, key: PAY_KEY },
    );

    assert.deepStrictEqual(
      waiting.actions?.map((action) => action.name),
      ['pay'],
    );
    assert.equal(confirming.actions, undefined);
    assert.equal(paid.actions, undefined);
  });

  it('tells why a returned order came back', () => {
    const screen = orderScreen(
      { ...pendingOrder, status: 'returned', trackingCode: 'TX02PX83TXC5G00' },
      { awaitingPayment: false, key: PAY_KEY },
    );

    assert.deepStrictEqual(screen.properties?.notice, {
      tone: 'info',
      text: 'A encomenda voltou para o nosso centro de distribuição, e o estorno do pagamento já foi pedido.',
    });
  });

  it('says nothing about a cancellation whose reason Commerce did not send', () => {
    const screen = orderScreen(
      { ...pendingOrder, status: 'cancelled' },
      { awaitingPayment: true, key: PAY_KEY },
    );

    assert.equal(screen.properties?.notice, undefined);
    assert.equal(screen.links?.[0]?.href, '/bff/v1/orders/0199a2b4-6f1c-7a3e-9b2d-5c8e1f4a7d20');
  });
});

describe('the tracking screen', () => {
  const moving: Tracking = {
    ...deliveredParcel,
    status: 'delivery_failed',
    carrier: 'mula-rapida',
    steps: [
      ...deliveredParcel.steps.slice(0, 6),
      {
        status: 'delivery_failed',
        at: '2026-09-28T00:50:19.827Z',
        hub: null,
        attempt: 1,
        reason: 'recipient_absent',
      },
    ],
  };

  it('asks for news while the parcel moves', () => {
    const screen = trackingScreen(moving);

    assert.deepStrictEqual(screen.class, ['screen', 'tracking', 'live']);
    assert.equal(screen.properties?.refreshAfterSeconds, 5);
  });

  it('says why a visit did not deliver', () => {
    const last = trackingScreen(moving).entities?.at(-1);

    assert.deepStrictEqual(last?.properties, {
      status: 'delivery_failed',
      label: 'Entrega não realizada: ninguém em casa',
      at: '2026-09-28T00:50:19.827Z',
      attempt: 1,
    });
  });

  it('shows the code of a carrier it has no name for yet', () => {
    assert.equal(trackingScreen(moving).properties?.carrierLabel, 'mula-rapida');
  });
});

describe('the storefront screens', () => {
  it('link the pages around the current one', () => {
    const middle = catalogScreen({ products: [dddBook], page: 2, perPage: 1, total: 3 });

    assert.deepStrictEqual(
      middle.links?.map((link) => [link.rel[0], link.href]),
      [
        ['self', '/bff/v1/products?page=2'],
        ['next', '/bff/v1/products?page=3'],
        ['prev', '/bff/v1/products?page=1'],
        ['up', '/bff/v1'],
      ],
    );
  });

  it('show a product out of line without the buy action', () => {
    const screen = productScreen({ ...dddBook, status: 'discontinued' });

    assert.equal(screen.actions, undefined);
    assert.deepStrictEqual(screen.properties?.notice, {
      tone: 'neutral',
      text: 'Este produto saiu de linha e não está mais à venda.',
    });
  });
});
