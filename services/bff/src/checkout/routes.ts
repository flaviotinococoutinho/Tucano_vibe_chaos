import type { FastifyPluginAsync } from 'fastify';
import {
  type Entity,
  FormReader,
  inStore,
  type KeyMaker,
  money,
  path,
  rel,
  screen,
  sendScreen,
} from '../hypermedia/index.ts';
import { orderScreen } from '../orders/index.ts';
import { activeProfile, firstNameOf, type Sessions, withActiveNamed } from '../session/index.ts';
import { ProductNotFound, SKU } from '../storefront/index.ts';
import type { Stores } from '../stores/index.ts';
import {
  type Catalog,
  type Commerce,
  MAX_UNITS_PER_ITEM,
  type Product,
  type Store,
  traceOf,
} from '../upstream/index.ts';
import { ProductOutOfLine } from './errors.ts';
import { newOrderOf, placeOrderAction, readOrderForm, refusalError } from './order-form.ts';

export type CheckoutOptions = {
  readonly catalog: Catalog;
  readonly commerce: Commerce;
  readonly sessions: Sessions;
  readonly stores: Stores;
  readonly newId: KeyMaker;
};

export function checkoutScreen(
  store: Store,
  product: Product,
  quantity: number,
  key: string,
): Entity {
  const { amount, currency } = product.price;

  return screen('checkout', {
    title: 'Finalizar pedido',
    properties: {
      sku: product.sku,
      name: product.name,
      quantity,
      unitPrice: money(amount, currency),
      subtotal: money(amount * quantity, currency),
    },
    actions: [placeOrderAction(store, product.sku, quantity, key)],
    links: [
      { rel: [rel.self], href: inStore(store.slug, '/checkout', { sku: product.sku, quantity }) },
      {
        rel: [rel.up],
        href: inStore(store.slug, path`/products/${product.sku}`),
        title: 'Voltar ao produto',
      },
    ],
  });
}

/** The checkout of a store, under `/v1/stores/:store`: only its own products get this far. */
export const checkoutRoutes: FastifyPluginAsync<CheckoutOptions> = async (app, options) => {
  const { catalog, commerce, sessions, stores, newId } = options;

  // The buy action of a product lands here, a GET with the SKU and the quantity.
  app.get('/checkout', async (request, reply) => {
    const store = stores.entered(request);
    const query = new FormReader(request.query);
    const quantity = query.integer(
      'quantity',
      1,
      MAX_UNITS_PER_ITEM,
      `Escolha de 1 a ${MAX_UNITS_PER_ITEM} unidades.`,
    );
    const sku = query.text('sku', { maxlength: 32, pattern: SKU, message: 'Escolha um produto.' });
    query.done();

    // A product of another store is not found here, like a SKU that does not exist.
    const product = await catalog.product(store.slug, sku, traceOf(request));
    if (product === null) {
      throw new ProductNotFound(sku);
    }
    if (product.status !== 'active') {
      throw new ProductOutOfLine(product.name);
    }
    // The session starts here, before the form, when the browser has none: every submission
    // of the form then goes as the same customer, so a retry sends the same body with its key.
    sessions.started(request, newId);

    return sendScreen(reply, checkoutScreen(store, product, quantity, newId()));
  });

  // 201 Created with the order screen, and Location where the order lives from now on.
  app.post('/orders', async (request, reply) => {
    const store = stores.entered(request);
    const form = readOrderForm(request.body);
    const session = sessions.started(request, newId);
    const placement = await commerce.placeOrder(
      form.idempotencyKey,
      newOrderOf(form, activeProfile(session).id, store),
      traceOf(request),
    );
    if (placement.outcome === 'refused') {
      throw refusalError(placement);
    }
    // A profile the checkout started is named by its first order, with the first name only.
    const named = withActiveNamed(session, firstNameOf(form.name));
    if (named !== session) {
      sessions.keep(request, named);
    }
    const { order } = placement;
    reply.header('location', inStore(store.slug, path`/orders/${order.orderId}`));

    return sendScreen(
      reply,
      orderScreen(
        store,
        { order, history: [], delivery: { news: 'none' } },
        { awaitingPayment: false, key: newId() },
      ),
      201,
    );
  });
};
