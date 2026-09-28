import type { FastifyPluginAsync } from 'fastify';
import {
  type Entity,
  FormReader,
  href,
  type KeyMaker,
  money,
  path,
  rel,
  screen,
  sendScreen,
} from '../hypermedia/index.ts';
import { orderScreen } from '../orders/index.ts';
import { ProductNotFound, SKU } from '../storefront/index.ts';
import {
  type Catalog,
  type Commerce,
  MAX_UNITS_PER_ITEM,
  type Product,
  traceOf,
} from '../upstream/index.ts';
import { ProductOutOfLine } from './errors.ts';
import { guestOf } from './guest.ts';
import { newOrderOf, placeOrderAction, readOrderForm, refusalError } from './order-form.ts';

export type CheckoutOptions = {
  readonly catalog: Catalog;
  readonly commerce: Commerce;
  readonly newId: KeyMaker;
  readonly secureCookies: boolean;
};

export function checkoutScreen(product: Product, quantity: number, key: string): Entity {
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
    actions: [placeOrderAction(product.sku, quantity, key)],
    links: [
      { rel: [rel.self], href: href('/checkout', { sku: product.sku, quantity }) },
      { rel: [rel.up], href: href(path`/products/${product.sku}`), title: 'Voltar ao produto' },
    ],
  });
}

export const checkoutRoutes: FastifyPluginAsync<CheckoutOptions> = async (app, options) => {
  const { catalog, commerce, newId } = options;
  const cookie = { newId, secure: options.secureCookies };

  // The buy action of a product lands here, a GET with the SKU and the quantity.
  app.get('/v1/checkout', async (request, reply) => {
    const query = new FormReader(request.query);
    const quantity = query.integer(
      'quantity',
      1,
      MAX_UNITS_PER_ITEM,
      `Escolha de 1 a ${MAX_UNITS_PER_ITEM} unidades.`,
    );
    const sku = query.text('sku', { maxlength: 32, pattern: SKU, message: 'Escolha um produto.' });
    query.done();

    const product = await catalog.product(sku, traceOf(request));
    if (product === null) {
      throw new ProductNotFound(sku);
    }
    if (product.status !== 'active') {
      throw new ProductOutOfLine(product.name);
    }
    // The guest cookie is set here, before the form, so every submission of it carries the same id.
    guestOf(request, reply, cookie);

    return sendScreen(reply, checkoutScreen(product, quantity, newId()));
  });

  // 201 Created with the order screen, and Location where the order lives from now on.
  app.post('/v1/orders', async (request, reply) => {
    const form = readOrderForm(request.body);
    const guestId = guestOf(request, reply, cookie);
    const placement = await commerce.placeOrder(
      form.idempotencyKey,
      newOrderOf(form, guestId),
      traceOf(request),
    );
    if (placement.outcome === 'refused') {
      throw refusalError(placement);
    }
    const { order } = placement;
    reply.header('location', href(path`/orders/${order.orderId}`));

    return sendScreen(reply, orderScreen(order, { awaitingPayment: false, key: newId() }), 201);
  });
};
