import assert from 'node:assert/strict';
import { after, before, describe, it } from 'node:test';
import { buildApp } from '../src/app.ts';
import { rel } from '../src/hypermedia/index.ts';
import { bffOver } from './support/bff.ts';
import { testConfig } from './support/config.ts';
import { FORM_KEY, PAY_KEY } from './support/example-screens.ts';
import { example } from './support/examples.ts';
import { type FakeServices, fakeServices, ids, serveStores } from './support/fake-services.ts';
import { orderForm } from './support/forms.ts';
import { sessionCookie, sessionSet } from './support/sessions.ts';
import {
  ana,
  anaShopping,
  customerOrderJson,
  dddBook,
  deliveredParcel,
  histories,
  orderJson,
  pendingOrder,
  productJson,
  trackingJson,
  visitor,
  visitorShopping,
} from './support/upstream-data.ts';

/** Every store screen of these journeys is in Arara Livros. */
const ARARA = '/v1/stores/arara';
const ORDER = pendingOrder.orderId;
/** The key the fake PSP answers 503 to, as if its circuit breaker were open. */
const KEY_WHILE_PSP_IS_OUT = '0199a2b4-9b21-7d62-a1e3-000000000503';
const SIREN = /^application\/vnd\.siren\+json/;
const ANA = sessionCookie(anaShopping);

/** The last order the fake Commerce received. */
function orderSentTo(services: FakeServices): { store?: string; customer?: { id?: string } } {
  const placed = services.received.filter(
    ({ method, url }) => method === 'POST' && url === '/v1/orders',
  );
  return (placed.at(-1)?.body ?? {}) as { store?: string; customer?: { id?: string } };
}

/** The addresses the fake services were asked for since a count of requests. */
function askedSince(services: FakeServices, count: number): string[] {
  return services.received.slice(count).map(({ method, url }) => `${method} ${url}`);
}

/** Picks the entities of a relation out of a screen as it came over the wire. */
function isA(relation: string): (entity: { rel: string[] }) => boolean {
  return (entity) => entity.rel.includes(relation);
}

describe('shopping', () => {
  let services: FakeServices;

  before(async () => {
    services = await fakeServices((app) => {
      serveStores(app);
      app.get<{ Params: { store: string } }>('/v1/stores/:store/products', async (request) => ({
        data: request.params.store === 'arara' ? [productJson(dddBook)] : [],
        page: 1,
        perPage: 20,
        total: request.params.store === 'arara' ? 1 : 0,
      }));
      app.get<{ Params: { store: string; sku: string } }>(
        '/v1/stores/:store/products/:sku',
        async (request, reply) => {
          const { store, sku } = request.params;
          if (store === 'arara' && sku === 'BOOK-DDD-001') {
            return productJson(dddBook);
          }
          if (store === 'arara' && sku === 'BOOK-OLD-001') {
            return productJson({ ...dddBook, sku: 'BOOK-OLD-001', status: 'discontinued' });
          }
          return reply.code(404).type('application/problem+json').send({ status: 404 });
        },
      );
      app.post('/v1/orders', async (request, reply) => {
        const { store, customer } = request.body as { store: string; customer: { email: string } };
        if (store !== 'arara') {
          return reply
            .code(422)
            .type('application/problem+json')
            .send({ status: 422, errors: { 'items.0.sku': ['not a product of the store'] } });
        }
        if (customer.email === 'stock@example.com') {
          return reply.code(409).type('application/problem+json').send({ status: 409 });
        }
        if (customer.email === 'discontinued@example.com') {
          return reply.code(409).type('application/problem+json').send({
            type: 'https://github.com/flaviotinococoutinho/chaos_playground/blob/develop/contracts/http/problems.md#product-unavailable',
            status: 409,
          });
        }
        if (customer.email === 'refused@example.com') {
          return reply
            .code(422)
            .type('application/problem+json')
            .send({ status: 422, errors: { 'customer.email': ['not an e-mail address'] } });
        }
        return reply.code(201).send(orderJson(pendingOrder));
      });
    });
  });

  after(() => services.close());

  it('opens the home of the platform with the stores the catalog lists', async () => {
    const received = services.received.length;
    const response = await bffOver(services).inject({ method: 'GET', url: '/v1' });

    assert.equal(response.statusCode, 200);
    assert.match(String(response.headers['content-type']), SIREN);
    assert.equal(response.headers['cache-control'], 'no-store');
    assert.deepStrictEqual(response.json(), example('home.json'));
    assert.deepStrictEqual(askedSince(services, received), ['GET /v1/stores']);
  });

  it('opens the home of a store, asking the catalog only for the store', async () => {
    const received = services.received.length;
    const response = await bffOver(services).inject({ method: 'GET', url: ARARA });

    assert.equal(response.statusCode, 200);
    assert.deepStrictEqual(response.json(), example('store.json'));
    assert.deepStrictEqual(askedSince(services, received), ['GET /v1/stores/arara']);
  });

  it('asks the catalog for the products of the store, with the correlation id along', async () => {
    const response = await bffOver(services).inject({
      method: 'GET',
      url: `${ARARA}/products`,
      headers: { 'x-correlation-id': 'req-9#1' },
    });

    assert.equal(response.statusCode, 200);
    assert.equal(response.json().entities.filter(isA(rel.item)).length, 1);
    assert.equal(services.received.at(-1)?.url, '/v1/stores/arara/products?page=1');
    assert.equal(services.received.at(-1)?.headers['x-correlation-id'], 'req-9#1');
  });

  it('names the shopper and the store in the navigation of every screen', async () => {
    const response = await bffOver(services).inject({
      method: 'GET',
      url: `${ARARA}/products`,
      headers: { cookie: ANA },
    });
    const navigation = response.json().entities.find(isA(rel.navigation));

    assert.deepStrictEqual(navigation.properties, {
      store: { slug: 'arara', name: 'Arara Livros', palette: 'arara', initial: 'A' },
      shopper: { profileId: ana.id, label: 'Ana', initial: 'A' },
    });
  });

  it('answers a product the store does not sell with a 404 in Portuguese', async () => {
    for (const url of [`${ARARA}/products/NOPE-001`, '/v1/stores/sabia/products/BOOK-DDD-001']) {
      const response = await bffOver(services).inject({ method: 'GET', url });

      assert.equal(response.statusCode, 404, url);
      assert.match(response.json().detail, /^Não encontrei o produto /, url);
    }
  });

  it('never asks the catalog for a SKU out of shape', async () => {
    const received = services.received.length;
    const response = await bffOver(services).inject({
      method: 'GET',
      url: `${ARARA}/products/no%20way`,
    });

    assert.equal(response.statusCode, 404);
    assert.deepStrictEqual(askedSince(services, received), ['GET /v1/stores/arara']);
  });

  it('opens the checkout and starts the session of a visitor', async () => {
    const response = await bffOver(services, { newId: ids(visitor.id, FORM_KEY) }).inject({
      method: 'GET',
      url: `${ARARA}/checkout?sku=BOOK-DDD-001&quantity=1`,
    });

    assert.equal(response.statusCode, 200);
    assert.deepStrictEqual(response.json(), example('checkout.json'));
    assert.match(
      String(response.headers['set-cookie']),
      /^tucano_session=[\w-]+\.[\w-]+; Path=\/bff; Max-Age=31536000; HttpOnly; SameSite=Lax; Secure$/,
    );
    assert.deepStrictEqual(sessionSet(response.headers['set-cookie']), visitorShopping);
  });

  it('keeps the session the browser already has', async () => {
    const response = await bffOver(services).inject({
      method: 'GET',
      url: `${ARARA}/checkout?sku=BOOK-DDD-001&quantity=2`,
      headers: { cookie: `theme=dark; ${ANA}` },
    });

    assert.equal(response.statusCode, 200);
    // Intl keeps a no-break space after the symbol, so R$ never ends a line alone.
    assert.equal(response.json().properties.subtotal.formatted, 'R$ 319,80');
    assert.equal(response.headers['set-cookie'], undefined);
  });

  it('refuses more units than an order takes, next to the quantity', async () => {
    const response = await bffOver(services).inject({
      method: 'GET',
      url: `${ARARA}/checkout?sku=BOOK-DDD-001&quantity=11`,
    });

    assert.equal(response.statusCode, 422);
    assert.deepStrictEqual(response.json().errors, { quantity: ['Escolha de 1 a 10 unidades.'] });
    assert.equal(response.headers['set-cookie'], undefined);
  });

  it('does not check out a product out of line', async () => {
    const response = await bffOver(services).inject({
      method: 'GET',
      url: `${ARARA}/checkout?sku=BOOK-OLD-001&quantity=1`,
    });

    assert.equal(response.statusCode, 409);
    assert.equal(
      response.json().detail,
      'Domain-Driven Design saiu de linha e não está mais à venda.',
    );
  });

  it('does not check out, nor start a session for, a product of another store', async () => {
    const response = await bffOver(services).inject({
      method: 'GET',
      url: '/v1/stores/sabia/checkout?sku=BOOK-DDD-001&quantity=1',
    });

    assert.equal(response.statusCode, 404);
    assert.equal(response.json().detail, 'Não encontrei o produto BOOK-DDD-001.');
    assert.equal(response.headers['set-cookie'], undefined);
  });

  it('places the order of the store as the profile shopping, with the key of the form', async () => {
    const response = await bffOver(services).inject({
      method: 'POST',
      url: `${ARARA}/orders`,
      headers: { cookie: ANA, 'x-correlation-id': 'req-9#2' },
      payload: orderForm,
    });

    assert.equal(response.statusCode, 201);
    assert.equal(response.headers.location, `/bff/v1/stores/arara/orders/${ORDER}`);
    assert.deepStrictEqual(response.json(), example('order-pending-payment.json'));
    assert.equal(response.headers['set-cookie'], undefined);
    const placed = services.received.at(-1);
    assert.equal(placed?.headers['idempotency-key'], FORM_KEY);
    assert.equal(placed?.headers['x-correlation-id'], 'req-9#2');
    assert.partialDeepStrictEqual(placed?.body, {
      store: 'arara',
      customer: { id: ana.id, email: 'ana@example.com' },
      shippingAddress: {
        thoroughfare: { type: 'Rua', name: 'da Bahia' },
        divisions: [{ kind: 'state', code: 'MG', name: 'Minas Gerais' }],
      },
      items: [{ sku: 'BOOK-DDD-001', quantity: 1 }],
    });
  });

  it('names the profile a checkout started after its first order, with the first name only', async () => {
    const response = await bffOver(services).inject({
      method: 'POST',
      url: `${ARARA}/orders`,
      headers: { cookie: sessionCookie(visitorShopping) },
      payload: { ...orderForm, name: '  Maria   da Silva ' },
    });

    assert.equal(response.statusCode, 201);
    assert.deepStrictEqual(sessionSet(response.headers['set-cookie']), {
      active: visitor.id,
      profiles: [{ id: visitor.id, name: 'Maria' }],
    });
    assert.equal(orderSentTo(services).customer?.id, visitor.id);
  });

  it('starts a session for an order that comes without one', async () => {
    const newcomer = '0199a2b4-7d1e-7f20-8a31-b4c5d6e7f809';
    const response = await bffOver(services, { newId: ids(newcomer, PAY_KEY) }).inject({
      method: 'POST',
      url: `${ARARA}/orders`,
      payload: orderForm,
    });

    assert.equal(response.statusCode, 201);
    assert.deepStrictEqual(sessionSet(response.headers['set-cookie']), {
      active: newcomer,
      profiles: [{ id: newcomer, name: 'Ana' }],
    });
    assert.equal(orderSentTo(services).customer?.id, newcomer);
  });

  it('gives back every mistake of the form at once, without bothering Commerce', async () => {
    const received = services.received.length;
    const response = await bffOver(services).inject({
      method: 'POST',
      url: `${ARARA}/orders`,
      payload: { ...orderForm, email: 'ana@', postalCode: '3016' },
    });

    assert.equal(response.statusCode, 422);
    const { correlationId: _id, instance, ...problem } = response.json();
    const {
      correlationId: _exampleId,
      instance: _exampleInstance,
      ...expected
    } = example('problems/validation.json') as Record<string, unknown>;
    assert.equal(instance, '/v1/stores/arara/orders');
    assert.deepStrictEqual(problem, expected);
    assert.deepStrictEqual(askedSince(services, received), ['GET /v1/stores/arara']);
    assert.equal(response.headers['set-cookie'], undefined);
  });

  it('says the stock ran out when Commerce cannot reserve it', async () => {
    const response = await bffOver(services).inject({
      method: 'POST',
      url: `${ARARA}/orders`,
      headers: { cookie: ANA },
      payload: { ...orderForm, email: 'stock@example.com' },
    });

    assert.equal(response.statusCode, 409);
    assert.match(response.json().detail, /estoque/);
  });

  it('keeps the session it started even when Commerce refuses the order', async () => {
    const newcomer = '0199a2b4-7d1e-7f20-8a31-b4c5d6e7f80a';
    const response = await bffOver(services, { newId: ids(newcomer) }).inject({
      method: 'POST',
      url: `${ARARA}/orders`,
      payload: { ...orderForm, email: 'stock@example.com' },
    });

    // A retry of the same form goes as the same customer, so the same key meets the same body.
    assert.equal(response.statusCode, 409);
    assert.deepStrictEqual(sessionSet(response.headers['set-cookie']), {
      active: newcomer,
      profiles: [{ id: newcomer, name: null }],
    });
  });

  it('tells a product out of line from a stock that ran out, by the type of the problem', async () => {
    const response = await bffOver(services).inject({
      method: 'POST',
      url: `${ARARA}/orders`,
      headers: { cookie: ANA },
      payload: { ...orderForm, email: 'discontinued@example.com' },
    });

    assert.equal(response.statusCode, 409);
    assert.equal(response.json().detail, 'Esse produto saiu de linha e não está mais à venda.');
  });

  it('puts what Commerce refused next to the field of the form', async () => {
    const response = await bffOver(services).inject({
      method: 'POST',
      url: `${ARARA}/orders`,
      headers: { cookie: ANA },
      payload: { ...orderForm, email: 'refused@example.com' },
    });

    assert.equal(response.statusCode, 422);
    assert.deepStrictEqual(response.json().errors, { email: ['Confira este campo.'] });
  });

  it('places an order with the store of its address, which Commerce holds each item to', async () => {
    const response = await bffOver(services).inject({
      method: 'POST',
      url: '/v1/stores/sabia/orders',
      headers: { cookie: ANA },
      payload: orderForm,
    });

    assert.equal(orderSentTo(services).store, 'sabia');
    assert.equal(response.statusCode, 422);
    assert.deepStrictEqual(response.json().errors, {
      sku: ['Esta loja não vende esse produto agora. Volte ao produto e comece o pedido de novo.'],
    });
  });
});

describe('paying', () => {
  let services: FakeServices;

  before(async () => {
    services = await fakeServices((app) => {
      serveStores(app);
      app.get<{ Params: { store: string; customerId: string; orderId: string } }>(
        '/v1/stores/:store/customers/:customerId/orders/:orderId',
        async (request, reply) => {
          const { store, customerId, orderId } = request.params;
          return store === 'arara' && customerId === ana.id && orderId === ORDER
            ? customerOrderJson(pendingOrder, histories.pending)
            : reply.code(404).send({ status: 404 });
        },
      );
      app.post('/v1/orders/:orderId/payments', async (request, reply) => {
        if (request.headers['idempotency-key'] === KEY_WHILE_PSP_IS_OUT) {
          return reply.code(503).header('retry-after', '17').send({ status: 503 });
        }
        return reply.code(202).send({ paymentId: 'p1', orderId: ORDER, status: 'pending' });
      });
    });
  });

  after(() => services.close());

  it('shows the order of the shopper with its pay form, read through the store', async () => {
    const response = await bffOver(services).inject({
      method: 'GET',
      url: `${ARARA}/orders/${ORDER}`,
      headers: { cookie: ANA },
    });

    assert.equal(response.statusCode, 200);
    assert.deepStrictEqual(response.json(), example('order-pending-payment.json'));
    assert.equal(
      services.received.at(-1)?.url,
      `/v1/stores/arara/customers/${ana.id}/orders/${ORDER}`,
    );
  });

  it('answers an order that does not exist with a 404', async () => {
    const response = await bffOver(services).inject({
      method: 'GET',
      url: `${ARARA}/orders/0199a2b4-0000-7000-8000-000000000000`,
      headers: { cookie: ANA },
    });

    assert.equal(response.statusCode, 404);
    assert.equal(
      response.json().detail,
      'Não encontrei esse pedido. Se ele foi feito com outro perfil, troque de perfil e abra de novo.',
    );
  });

  it('follows the payment until the PSP answers', async () => {
    const response = await bffOver(services).inject({
      method: 'POST',
      url: `${ARARA}/orders/${ORDER}/payments`,
      headers: { cookie: ANA },
      payload: { idempotencyKey: PAY_KEY, cardToken: 'tok_visa' },
    });

    assert.equal(response.statusCode, 202);
    assert.equal(
      response.headers.location,
      `/bff/v1/stores/arara/orders/${ORDER}?awaiting=payment`,
    );
    assert.deepStrictEqual(response.json(), example('order-awaiting-payment.json'));
    assert.equal(services.received.at(-2)?.headers['idempotency-key'], PAY_KEY);
  });

  it('says when to try again while payments are out', async () => {
    const response = await bffOver(services).inject({
      method: 'POST',
      url: `${ARARA}/orders/${ORDER}/payments`,
      headers: { cookie: ANA },
      payload: { idempotencyKey: KEY_WHILE_PSP_IS_OUT, cardToken: 'tok_visa' },
    });

    assert.equal(response.statusCode, 503);
    assert.equal(response.headers['retry-after'], '17');
    assert.equal(
      response.json().detail,
      'O pagamento está fora do ar agora. Tente de novo em 17 segundos.',
    );
  });

  it('refuses a card that is not one of the test cards', async () => {
    const received = services.received.length;
    const response = await bffOver(services).inject({
      method: 'POST',
      url: `${ARARA}/orders/${ORDER}/payments`,
      headers: { cookie: ANA },
      payload: { idempotencyKey: PAY_KEY, cardToken: '4111111111111111' },
    });

    assert.equal(response.statusCode, 422);
    assert.deepStrictEqual(response.json().errors, {
      cardToken: ['Escolha um dos cartões de teste.'],
    });
    assert.deepStrictEqual(askedSince(services, received), ['GET /v1/stores/arara']);
  });
});

describe('tracking', () => {
  let services: FakeServices;
  /** A parcel from before the stores: logistics knows it, and no store shows it. */
  const BEFORE_THE_STORES = 'TX02PX83TXC5G11';

  before(async () => {
    services = await fakeServices((app) => {
      serveStores(app);
      app.get<{ Params: { code: string } }>('/v1/tracking/:code', async (request, reply) => {
        const { code } = request.params;
        if (code === deliveredParcel.trackingCode) {
          return { ...trackingJson(deliveredParcel), store: 'arara' };
        }
        if (code === BEFORE_THE_STORES) {
          return { ...trackingJson({ ...deliveredParcel, trackingCode: code }), store: null };
        }
        return reply.code(404).send({ status: 404 });
      });
      app.get<{ Params: { store: string; code: string } }>(
        '/v1/stores/:store/tracking/:code',
        async (request, reply) =>
          request.params.store === 'arara' && request.params.code === deliveredParcel.trackingCode
            ? { ...trackingJson(deliveredParcel), store: 'arara' }
            : reply.code(404).send({ status: 404 }),
      );
    });
  });

  after(() => services.close());

  it('finds the store of a typed code and sends the browser to its page, in that store', async () => {
    const response = await bffOver(services).inject({
      method: 'GET',
      url: '/v1/tracking?code=%20tx02px83txc5goo%20',
    });

    assert.equal(response.statusCode, 303);
    assert.equal(response.headers.location, '/bff/v1/stores/arara/tracking/TX02PX83TXC5G00');
    assert.equal(services.received.at(-1)?.url, '/v1/tracking/TX02PX83TXC5G00');
  });

  it('sends a code typed in a store to the page in that store, without looking it up', async () => {
    const received = services.received.length;
    const response = await bffOver(services).inject({
      method: 'GET',
      url: `${ARARA}/tracking?code=%20tx02px83txc5goo%20`,
    });

    assert.equal(response.statusCode, 303);
    assert.equal(response.headers.location, '/bff/v1/stores/arara/tracking/TX02PX83TXC5G00');
    assert.deepStrictEqual(askedSince(services, received), ['GET /v1/stores/arara']);
  });

  it('refuses a code out of shape next to the field, on the platform and in a store', async () => {
    for (const url of ['/v1/tracking?code=abc', `${ARARA}/tracking?code=abc`, '/v1/tracking']) {
      const response = await bffOver(services).inject({ method: 'GET', url });

      assert.equal(response.statusCode, 422, url);
      assert.deepStrictEqual(Object.keys(response.json().errors), ['code'], url);
    }
  });

  it('shows the journey of the parcel of the store', async () => {
    const response = await bffOver(services).inject({
      method: 'GET',
      url: `${ARARA}/tracking/TX02PX83TXC5G00`,
    });

    assert.equal(response.statusCode, 200);
    assert.deepStrictEqual(response.json(), example('tracking.json'));
    assert.equal(services.received.at(-1)?.url, '/v1/stores/arara/tracking/TX02PX83TXC5G00');
  });

  it('explains a code with no news yet, on the platform and in a store', async () => {
    for (const url of ['/v1/tracking?code=TX02PX83TXC5G99', `${ARARA}/tracking/TX02PX83TXC5G99`]) {
      const response = await bffOver(services).inject({ method: 'GET', url });

      assert.equal(response.statusCode, 404, url);
      assert.match(
        response.json().detail,
        /^Ainda não há notícias da entrega TX02PX83TXC5G99/,
        url,
      );
    }
  });

  it('finds no store for a parcel from before the stores', async () => {
    const response = await bffOver(services).inject({
      method: 'GET',
      url: `/v1/tracking?code=${BEFORE_THE_STORES}`,
    });

    assert.equal(response.statusCode, 404);
    assert.equal(response.headers.location, undefined);
  });
});

describe('when a service fails', () => {
  it('answers 503 with Retry-After when the service does not answer in time', async () => {
    const services = await fakeServices((app) => {
      serveStores(app);
      app.get('/v1/stores/:store/products', async () => {
        await new Promise((resolve) => setTimeout(resolve, 1000));
        return { data: [], page: 1, perPage: 20, total: 0 };
      });
    });
    try {
      const response = await bffOver(services, { env: { UPSTREAM_TIMEOUT_MS: '200' } }).inject({
        method: 'GET',
        url: `${ARARA}/products`,
      });

      assert.equal(response.statusCode, 503);
      assert.equal(response.headers['retry-after'], '5');
      assert.equal(
        response.json().detail,
        'Agora não consegui falar com o catálogo. Tente de novo em 5 segundos.',
      );
    } finally {
      await services.close();
    }
  });

  it('answers 503 when nothing listens where the service should be', async () => {
    const config = testConfig({ LOGISTICS_URL: 'http://127.0.0.1:9' });
    const response = await buildApp({ config }).inject({
      method: 'GET',
      url: '/v1/tracking?code=TX02PX83TXC5G00',
    });

    assert.equal(response.statusCode, 503);
    assert.equal(response.headers['retry-after'], '5');
  });

  it('hides a broken contract behind a 500, for a person to look at the logs', async () => {
    const services = await fakeServices((app) => {
      serveStores(app);
      app.get('/v1/stores/:store/products', async () => ({ data: 'not a list' }));
    });
    try {
      const response = await bffOver(services).inject({ method: 'GET', url: `${ARARA}/products` });

      assert.equal(response.statusCode, 500);
      assert.doesNotMatch(response.body, /not a list/);
    } finally {
      await services.close();
    }
  });

  it('stays ready while the services behind are out', async () => {
    const config = testConfig({
      CATALOG_URL: 'http://127.0.0.1:9',
      COMMERCE_URL: 'http://127.0.0.1:9',
      LOGISTICS_URL: 'http://127.0.0.1:9',
    });
    const response = await buildApp({ config }).inject({ method: 'GET', url: '/health/ready' });

    assert.equal(response.statusCode, 200);
  });
});
